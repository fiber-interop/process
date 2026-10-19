<?php

namespace fiber\process;

/**
 * A started child process: returned by start(), awaitable with fiber\await() as often as you
 * like (it settles once, to a Result or a TimeoutException). $pid is the child's pid; with a
 * string command that is the /bin/sh running it.
 */
final class Process
{
    /** Seconds between SIGTERM and SIGKILL when a timeout expires. */
    public const GRACE = 2.0;

    /**
     * stdin is written at most this many bytes per fwrite(). Swoole 6.2 (SWOOLE_HOOK_PROC) turns the pipes into
     * coroutine sockets whose fwrite() sends part of a buffer larger than the free socket space but returns 0,
     * so the retry duplicates data (and it ends in false plus a notice that @ cannot hide from PHPUnit); see
     * tests/upstream/swoole-hooked-pipe-large-fwrite.php. Once the fd polls writable, 64 KB always fits.
     */
    private const CHUNK = 65536;

    public readonly int $pid;
    /** @var \resource */
    private $proc;
    /** @var array<int, resource> */
    private array $pipes;
    private string $out = '';
    private string $err = '';
    private ?array $status = null;
    private float $deadline;
    private bool $timedOut = false;
    private bool $settled = false;
    private ?Result $result = null;
    private ?\Throwable $error = null;
    /** @var list<array{?callable, ?callable}> */
    private array $waiters = [];

    /** @internal use start() */
    public function __construct(array|string $command, ?string $cwd, ?array $env, private ?string $stdin, float $timeout)
    {
        $proc = \proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $cwd, $env);
        if ($proc === false) {
            throw new \RuntimeException('proc_open() failed: ' . (\error_get_last()['message'] ?? 'unknown error'));
        }
        $this->proc = $proc;
        $this->pipes = $pipes;
        foreach ($pipes as $p) {
            \stream_set_blocking($p, false);
        }
        $this->pid = \proc_get_status($proc)['pid'];
        $this->deadline = $timeout > 0 ? \microtime(true) + $timeout : \PHP_FLOAT_MAX;
        if ($this->stdin === null || $this->stdin === '') {
            \fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }
    }

    /** Send $signal (default SIGTERM) to the process. False if it has already exited. */
    public function kill(int $signal = 15): bool
    {
        return !$this->exited() && \proc_terminate($this->proc, $signal);
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        if (!$this->settled) {
            $this->waiters[] = [$onFulfilled, $onRejected];
        } elseif ($this->error === null) {
            $onFulfilled && $onFulfilled($this->result);
        } else {
            $onRejected && $onRejected($this->error);
        }
    }

    /** @internal */
    public function settle(?Result $result, ?\Throwable $error): void
    {
        [$this->settled, $this->result, $this->error] = [true, $result, $error];
        foreach ($this->waiters as [$ok, $fail]) {
            $error === null ? ($ok && $ok($result)) : ($fail && $fail($error));
        }
        $this->waiters = [];
    }

    /** @internal Inside run(): one task per pipe on fiber\readable()/writable(), exit by polling. */
    public function drive(): Result
    {
        $tasks = [];
        if (isset($this->pipes[0])) {
            $tasks[] = \fiber\go(function () {
                $p = $this->pipes[0];
                while ($this->stdin !== '' && $this->wait('writable', $p)) {
                    $n = @\fwrite($p, \substr($this->stdin, 0, self::CHUNK));
                    if ($n === false) {
                        break;                         // EPIPE: the child closed its stdin
                    }
                    $this->stdin = (string) \substr($this->stdin, $n);
                }
                \fclose($p);
            });
        }
        foreach ([1 => 'out', 2 => 'err'] as $fd => $buf) {
            $tasks[] = \fiber\go(function () use ($fd, $buf) {
                $p = $this->pipes[$fd];
                while (!\feof($p) && $this->wait('readable', $p)) {
                    $this->$buf .= (string) \fread($p, 65536);
                }
                \fclose($p);
            });
        }
        // PHP exposes no fd for child exit (no pidfd / SIGCHLD hook), so poll with back-off.
        $delay = 0.001;
        while (!$this->exited()) {
            if (\microtime(true) >= $this->deadline) {
                $this->expire(static fn($s) => \fiber\sleep($s));
                break;
            }
            \fiber\sleep(\min($delay, \max(0.001, $this->deadline - \microtime(true))));
            $delay = \min($delay * 2, 0.01);
        }
        \fiber\await_all($tasks);
        return $this->finish();
    }

    /** @internal Outside run(): plain blocking stream_select() over the pipes. */
    public function block(): Result
    {
        $names = [1 => 'out', 2 => 'err'];
        while ($this->pipes !== []) {
            $r = \array_intersect_key($this->pipes, $names);
            $w = isset($this->pipes[0]) ? [0 => $this->pipes[0]] : [];
            $e = null;
            $left = $this->deadline - \microtime(true);
            if ($left <= 0) {
                $this->expire(static fn($s) => \usleep((int) ($s * 1e6)));
                break;
            }
            if (@\stream_select($r, $w, $e, 0, (int) (\min($left, 1.0) * 1e6)) === false) {
                continue;                              // EINTR
            }
            foreach ($w as $p) {
                $n = @\fwrite($p, \substr($this->stdin, 0, self::CHUNK));
                $this->stdin = $n === false ? '' : (string) \substr($this->stdin, $n);
                if ($this->stdin === '') {
                    \fclose($p);
                    unset($this->pipes[0]);
                }
            }
            foreach ($r as $p) {
                $fd = \array_search($p, $this->pipes, true);
                $this->{$names[$fd]} .= (string) \fread($p, 65536);
                if (\feof($p)) {
                    \fclose($p);
                    unset($this->pipes[$fd]);
                }
            }
        }
        $delay = 0.001;
        while (!$this->exited()) {
            \usleep((int) ($delay * 1e6));
            $delay = \min($delay * 2, 0.01);
        }
        return $this->finish();
    }

    /** Waits for $p; false once the timeout (plus grace) has passed and the pipe is abandoned. */
    private function wait(string $what, $p): bool
    {
        $limit = $this->deadline === \PHP_FLOAT_MAX ? \PHP_FLOAT_MAX : $this->deadline + self::GRACE + 0.5;
        try {
            ('fiber\\' . $what)($p, \max(0.001, $limit - \microtime(true)));
            return true;
        } catch (\fiber\TimeoutException) {
            return false;
        }
    }

    private function expire(callable $sleep): void
    {
        $this->timedOut = true;
        \proc_terminate($this->proc, 15);
        for ($t = 0.0; $t < self::GRACE && !$this->exited(); $t += 0.01) {
            $sleep(0.01);
        }
        if (!$this->exited()) {
            \proc_terminate($this->proc, 9);
            while (!$this->exited()) {
                $sleep(0.005);
            }
        }
        if ($this->pipes !== [] && \fiber\Registry::$active === []) {
            foreach ($this->pipes as $p) {       // a grandchild may still hold them open
                \fclose($p);
            }
            $this->pipes = [];
        }
    }

    private function exited(): bool
    {
        if ($this->status === null) {
            $s = \proc_get_status($this->proc);
            if (!$s['running']) {
                $this->status = $s;
            }
        }
        return $this->status !== null;
    }

    private function finish(): Result
    {
        \proc_close($this->proc);
        $s = $this->status;
        $result = new Result($s['exitcode'], $this->out, $this->err, $s['signaled'] ? $s['termsig'] : null);
        if ($this->timedOut) {
            throw new TimeoutException('Process ' . $this->pid . ' timed out and was killed', $result);
        }
        return $result;
    }
}

<?php

namespace async\process\Tests;

use async\process\Result;
use async\process\TimeoutException;
use PHPUnit\Framework\TestCase;

use function async\{await, await_all, go};

final class ProcessTest extends TestCase
{
    public function testOverlap(): void
    {
        $t = microtime(true);
        $results = \async\run(fn() => await_all(array_map(
            fn() => go(fn() => \async\process\run('sleep 0.2; echo done')),
            range(1, 10),
        )));
        $this->assertLessThan(1.5, microtime(true) - $t);
        $this->assertCount(10, $results);
        foreach ($results as $r) {
            $this->assertSame("done\n", $r->stdout);
        }
    }

    public function testOtherTasksRunWhileWaiting(): void
    {
        $ticks = 0;
        \async\run(function () use (&$ticks) {
            go(function () use (&$ticks) {
                for ($i = 0; $i < 5; $i++) {
                    \async\sleep(0.02);
                    $ticks++;
                }
            });
            \async\process\run(['sleep', '0.3']);
            $this->assertSame(5, $ticks);
        });
    }

    public function testStdoutStderrSeparated(): void
    {
        foreach ([false, true] as $inRun) {
            $f = fn() => \async\process\run('echo out; echo err >&2; exit 3');
            $r = $inRun ? \async\run($f) : $f();
            $this->assertSame("out\n", $r->stdout);
            $this->assertSame("err\n", $r->stderr);
            $this->assertSame(3, $r->exitCode);
            $this->assertNull($r->signal);
        }
    }

    public function testStdinArgvCwdEnv(): void
    {
        $r = \async\run(fn() => \async\process\run(['cat'], stdin: "hello\n"));
        $this->assertSame("hello\n", $r->stdout);
        $r = \async\run(fn() => \async\process\run('pwd; echo $FOO', '/tmp', ['FOO' => 'bar']));
        $this->assertSame("/tmp\nbar\n", $r->stdout);
        $this->assertSame(0, $r->exitCode);
    }

    /** The classic proc_open deadlock: child fills stdout and stderr while we feed a big stdin. */
    public function testLargeOutputAndInputNoDeadlock(): void
    {
        $in = str_repeat("0123456789abcdef", 256 * 1024);   // 4 MB
        $cmd = ['sh', '-c', 'head -c 4194304 /dev/zero | tr "\\0" e >&2; cat'];
        foreach ([false, true] as $inRun) {
            $f = fn() => \async\process\run($cmd, stdin: $in, timeout: 20);
            $r = $inRun ? \async\run($f) : $f();
            $this->assertSame(strlen($in), strlen($r->stdout));
            $this->assertSame(md5($in), md5($r->stdout));
            $this->assertSame(4194304, strlen($r->stderr));
        }
    }

    public function testTimeoutKills(): void
    {
        foreach ([false, true] as $inRun) {
            $t = microtime(true);
            $f = fn() => \async\process\run(['sh', '-c', 'echo partial; exec sleep 10'], timeout: 0.3);
            try {
                $inRun ? \async\run($f) : $f();
                $this->fail('no timeout');
            } catch (TimeoutException $e) {
                $this->assertInstanceOf(\async\TimeoutException::class, $e);
                $this->assertSame("partial\n", $e->result->stdout);
                $this->assertSame(15, $e->result->signal);
            }
            $this->assertLessThan(2, microtime(true) - $t);
        }
    }

    public function testTimeoutSigkillAfterGrace(): void
    {
        $t = microtime(true);
        try {
            \async\run(fn() => \async\process\run(['sh', '-c', 'trap "" TERM; while :; do sleep 0.05; done'], timeout: 0.2));
            $this->fail('no timeout');
        } catch (TimeoutException $e) {
            $this->assertSame(9, $e->result->signal);
        }
        $this->assertLessThan(5, microtime(true) - $t);
    }

    public function testStartAwaitRepeatAndKill(): void
    {
        \async\run(function () {
            $p = \async\process\start(['sleep', '5']);
            $this->assertGreaterThan(0, $p->pid);
            \async\sleep(0.05);
            $this->assertTrue($p->kill());
            $r = await($p);
            $this->assertSame(15, $r->signal);
            $this->assertSame($r, await($p));
        });
        $p = \async\process\start(['echo', 'x']);   // outside run(): already finished
        $this->assertFalse($p->kill());
        $this->assertSame("x\n", \async\run(fn() => await($p))->stdout);
    }

    public function testOutsideRunBlocks(): void
    {
        $t = microtime(true);
        $r = \async\process\run('sleep 0.1; echo hi');
        $this->assertInstanceOf(Result::class, $r);
        $this->assertSame("hi\n", $r->stdout);
        $this->assertGreaterThanOrEqual(0.1, microtime(true) - $t);
    }
}

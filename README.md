# fiber-interop/process

Cooperative `proc_open()` for [fiber-interop/api](https://github.com/fiber-interop/api): inside
`fiber\run()` running a child process suspends only the calling task, on whichever adapter is
installed. Outside `run()` the same calls simply block.

```
composer require fiber-interop/process
```

```php
use function fiber\{run, go, await_all};

[$a, $b] = run(fn() => await_all([
    go(fn() => fiber\process\run(['git', 'log', '-1', '--format=%H'])),
    go(fn() => fiber\process\run('gzip -c | wc -c', stdin: $bigString, timeout: 10)),
]));
echo $a->stdout, $b->stdout, $b->exitCode;
```

## API

- `fiber\process\run(array|string $command, ?string $cwd = null, ?array $env = null, ?string $stdin = null, float $timeout = 0): Result`
  runs the command to completion. `$command` is as for `proc_open()`: an argv array (no shell) or a
  string run by `/bin/sh -c`. `$env` null inherits the environment. `$stdin` is written to the child,
  then its stdin is closed; with null the child gets an empty, closed stdin.
- `Result` has `exitCode`, `stdout`, `stderr` and `signal` (the terminating signal, or null if the
  process exited normally; `exitCode` is then -1).
- `fiber\process\start(...)` takes the same arguments and returns a `Process`: `fiber\await($p)`
  yields the `Result` (await it as often as you like, it settles once), `$p->pid` is the child's pid
  (the `/bin/sh` for a string command), `$p->kill(int $signal = SIGTERM)` signals it. Outside
  `run()` the process has already finished when `start()` returns.
- `$timeout > 0`: when it expires the process gets SIGTERM, and SIGKILL `Process::GRACE` (2) seconds
  later if still alive; then `fiber\process\TimeoutException` is thrown. It implements
  `fiber\TimeoutException`, and its `$result` holds the output captured until the kill.

## How it waits

Inside `run()`, all three pipes are non-blocking and each gets its own task: stdin is written on
`fiber\writable()`, stdout and stderr are drained on `fiber\readable()` concurrently. So a child that
fills its stdout/stderr pipe while we are still feeding its stdin cannot deadlock (the classic
`proc_open()` bug; tested with 4 MB each way). Outside `run()` the same is done with
`stream_select()` over the pipes.

Process exit has no file descriptor PHP can wait on, so it is detected by polling
`proc_get_status()` with `fiber\sleep()` between polls, backing off from 1 ms to 10 ms. Exit is
therefore noticed up to 10 ms late; no CPU is burnt meanwhile. The fix belongs in php-src: expose a
pidfd (Linux `pidfd_open()`, or a kqueue `EVFILT_PROC` equivalent) from `proc_get_status()` or a
`proc_get_fd()`, so the exit can be awaited with `fiber\readable()` like any socket.

## Limits

- Output is buffered in memory: no streaming access to stdout while the process runs.
- On timeout, pipes still held open by a grandchild (e.g. a background job of the shell) are
  abandoned `GRACE + 0.5` s after the deadline; a string command that `exec`s its last program
  avoids this.
- `$p->kill()` signals only the direct child (`proc_terminate()`), not its process group.
- Under Swoole, `SWOOLE_HOOK_ALL` includes `SWOOLE_HOOK_PROC`, which makes `proc_open()` itself
  coroutine-aware; this package's path waits on `fiber\readable()` either way and passes the same
  suite.

## Tests

No external services are needed. `FI_RUNTIME=revolt|amp|react|phasync|swoole` picks the adapter:

```
FI_RUNTIME=amp vendor/bin/phpunit
FI_RUNTIME=phasync php -d extension=phasync.so vendor/bin/phpunit
```

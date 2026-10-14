<?php

namespace async\process;

/**
 * Run $command to completion and return its Result. $command is an argv array (no shell) or a
 * string run by /bin/sh -c, as with proc_open(). $env null inherits the environment, $stdin is
 * written to the child and then closed (null: the child gets an empty, closed stdin).
 *
 * Inside async\run() only the calling task waits; outside it this blocks like proc_open() plus
 * stream_select(). With $timeout > 0 the process gets SIGTERM when it expires, SIGKILL two
 * seconds later if still alive, and TimeoutException (an async\TimeoutException) is thrown.
 *
 * @throws \RuntimeException if proc_open() fails
 * @throws TimeoutException
 */
function run(array|string $command, ?string $cwd = null, ?array $env = null, ?string $stdin = null, float $timeout = 0): Result
{
    $p = new Process($command, $cwd, $env, $stdin, $timeout);
    return \async\Registry::$active === [] ? $p->block() : $p->drive();
}

/**
 * Start $command and return its Process: await it (any number of times) for the Result, or
 * kill() it. Same arguments as run(). Outside async\run() the process has completed when start()
 * returns, so awaiting it later just yields the Result (or throws its TimeoutException).
 */
function start(array|string $command, ?string $cwd = null, ?array $env = null, ?string $stdin = null, float $timeout = 0): Process
{
    $p = new Process($command, $cwd, $env, $stdin, $timeout);
    if (\async\Registry::$active === []) {
        try {
            $p->settle($p->block(), null);
        } catch (\Throwable $e) {
            $p->settle(null, $e);
        }
    } else {
        \async\go(static function () use ($p) {
            try {
                $p->settle($p->drive(), null);
            } catch (\Throwable $e) {
                $p->settle(null, $e);
            }
        });
    }
    return $p;
}

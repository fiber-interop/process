<?php

namespace async\process;

/** What a finished process left behind. $signal is the terminating signal, or null if it exited. */
final class Result
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?int $signal = null,
    ) {
    }
}

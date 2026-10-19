<?php

namespace fiber\process;

/** The process outlived its timeout and was killed; $result holds what it wrote until then. */
final class TimeoutException extends \RuntimeException implements \fiber\TimeoutException
{
    public function __construct(string $message, public readonly Result $result)
    {
        parent::__construct($message);
    }
}

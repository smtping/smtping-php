<?php

declare(strict_types=1);

namespace Smtping\Exception;

class JobFailedException extends SmtpingException
{
    /** @var array<string, mixed> */
    public array $job;

    /** @param array<string, mixed> $job */
    public function __construct(string $message, array $job = [])
    {
        parent::__construct($message, 0, $job);
        $this->job = $job;
    }
}

<?php

declare(strict_types=1);

namespace Smtping\Exception;

class SmtpingException extends \RuntimeException
{
    /** @var mixed */
    public $body;
    public int $status;

    /** @param mixed $body */
    public function __construct(string $message, int $status = 0, $body = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->body = $body;
    }
}

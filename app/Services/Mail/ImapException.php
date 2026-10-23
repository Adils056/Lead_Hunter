<?php

namespace App\Services\Mail;

class ImapException extends \RuntimeException
{
    public function __construct(string $message, public bool $isAuth = false)
    {
        parent::__construct($message);
    }
}

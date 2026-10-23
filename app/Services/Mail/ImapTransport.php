<?php

namespace App\Services\Mail;

interface ImapTransport
{
    public function open(string $host, int $port, int $timeout): void;

    public function write(string $data): void;

    public function readLine(): string;

    public function read(int $bytes): string;

    public function close(): void;
}

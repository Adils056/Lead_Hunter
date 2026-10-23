<?php

namespace App\Services\Mail;

/** TLS socket transport (implicit TLS, e.g. imap.gmail.com:993) with certificate verification. */
class SocketImapTransport implements ImapTransport
{
    protected $stream;

    public function open(string $host, int $port, int $timeout): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $this->stream = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->stream) {
            throw new \RuntimeException("IMAP connect failed ({$host}:{$port}): {$errstr} ({$errno})");
        }
        stream_set_timeout($this->stream, $timeout);
    }

    public function write(string $data): void
    {
        if (@fwrite($this->stream, $data) === false) {
            throw new \RuntimeException('IMAP write failed');
        }
    }

    public function readLine(): string
    {
        $line = fgets($this->stream, 65536); // cap line length
        if ($line === false) {
            throw new \RuntimeException('IMAP connection closed or timed out');
        }

        return $line;
    }

    public const MAX_LITERAL = 26214400; // 25 MB per message

    public function read(int $bytes): string
    {
        if ($bytes > self::MAX_LITERAL) {
            throw new \RuntimeException("IMAP literal too large ({$bytes} bytes)");
        }
        $buf = '';
        while (strlen($buf) < $bytes) {
            $chunk = fread($this->stream, $bytes - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('IMAP literal read failed');
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    public function close(): void
    {
        if ($this->stream) {
            @fclose($this->stream);
        }
    }
}

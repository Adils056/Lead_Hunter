<?php

namespace App\Services\Mail;

/**
 * Minimal, read-only IMAP4rev1 client: LOGIN, SELECT, UID SEARCH, UID FETCH BODY.PEEK[] (does not mark read), LOGOUT.
 * No external dependency (works on XAMPP without ext-imap).
 */
class ImapClient
{
    protected int $tag = 0;

    public function __construct(protected ImapTransport $t)
    {
    }

    public function connect(string $host, int $port, int $timeout = 30): void
    {
        $this->t->open($host, $port, $timeout);
        $greeting = $this->t->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new \RuntimeException('Unexpected IMAP greeting: ' . trim($greeting));
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->command('LOGIN ' . $this->quote($user) . ' ' . $this->quote($pass), 'login');
    }

    /** @return array{exists:int, uidvalidity:?int} */
    public function select(string $mailbox): array
    {
        [$lines] = $this->command('SELECT ' . $this->quote($mailbox));
        $exists = 0;
        $uidv = null;
        foreach ($lines as $l) {
            if (preg_match('/^\* (\d+) EXISTS/', $l, $m)) {
                $exists = (int) $m[1];
            }
            if (preg_match('/UIDVALIDITY (\d+)/', $l, $m)) {
                $uidv = (int) $m[1];
            }
        }

        return ['exists' => $exists, 'uidvalidity' => $uidv];
    }

    /** @return int[] */
    public function uidSearch(string $criteria): array
    {
        [$lines] = $this->command('UID SEARCH ' . $criteria);
        foreach ($lines as $l) {
            if (preg_match('/^\* SEARCH ?(.*)$/', rtrim($l), $m)) {
                return array_values(array_map('intval', array_filter(explode(' ', trim($m[1])), 'strlen')));
            }
        }

        return [];
    }

    public function fetchRaw(int $uid): ?string
    {
        [, $literals] = $this->command("UID FETCH {$uid} (BODY.PEEK[])");

        return $literals[0] ?? null;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
        }
        $this->t->close();
    }

    /** @return array{0: string[], 1: string[]} untagged lines, literals */
    protected function command(string $cmd, string $what = 'command'): array
    {
        $tag = 'A' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        $this->t->write("{$tag} {$cmd}\r\n");
        $lines = [];
        $literals = [];
        $guard = 0;
        while (true) {
            $line = $this->t->readLine();
            if (++$guard > 100000) {
                throw new \RuntimeException('IMAP response too long');
            }
            if (preg_match('/\{(\d{1,9})\}\r?\n$/', $line, $m)) {
                if ((int) $m[1] > 26214400) {
                    throw new \RuntimeException('IMAP literal too large');
                }
                $literals[] = $this->t->read((int) $m[1]);
                $lines[] = $line;
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $line)) {
                    $msg = trim(substr($line, strlen($tag) + 1));
                    throw new ImapException($what === 'login' ? "IMAP login failed: {$msg}" : "IMAP {$what} failed: {$msg}", $what === 'login');
                }
                break;
            }
            $lines[] = $line;
        }

        return [$lines, $literals];
    }

    protected function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}

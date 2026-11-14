<?php

namespace ControleOnline\Service\Email;

/**
 * Minimal IMAP4rev1 client over a TLS socket (PHP 8.4+ has no ext-imap in core).
 * Only what the inbox poller needs: LOGIN, SELECT, UID SEARCH UNSEEN, UID FETCH BODY.PEEK[],
 * UID STORE +FLAGS (\Seen), LOGOUT.
 */
class ImapClient
{
    /** @var resource|null */
    private $socket = null;
    private int $tag = 0;

    public function connect(string $host, int $port = 993, string $encryption = 'ssl', int $timeout = 20): void
    {
        $scheme = $encryption === 'none' ? 'tcp' : 'ssl';
        $socket = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $error, $timeout);
        if (!$socket) {
            throw new \RuntimeException("IMAP connect failed: {$error} ({$errno})");
        }
        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
        $this->readLine(); // server greeting
    }

    public function login(string $username, string $password): void
    {
        $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
    }

    public function select(string $mailbox = 'INBOX'): void
    {
        $this->command('SELECT ' . $this->quote($mailbox));
    }

    /** @return list<int> */
    public function searchUnseen(): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH UNSEEN') as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }

        return $uids;
    }

    /** Raw RFC 822 message, without setting \Seen. */
    public function fetchRaw(int $uid): string
    {
        return implode('', $this->command("UID FETCH {$uid} BODY.PEEK[]", collectLiterals: true));
    }

    public function markSeen(int $uid): void
    {
        $this->command("UID STORE {$uid} +FLAGS (\\Seen)");
    }

    public function logout(): void
    {
        if ($this->socket) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * @return list<string> untagged lines, or literal payloads when $collectLiterals
     */
    private function command(string $command, bool $collectLiterals = false): array
    {
        if (!$this->socket) {
            throw new \RuntimeException('IMAP not connected');
        }
        $tag = 'A' . (++$this->tag);
        fwrite($this->socket, "{$tag} {$command}\r\n");

        $out = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}$/', rtrim($line), $m)) {
                $literal = $this->readBytes((int) $m[1]);
                if ($collectLiterals) {
                    $out[] = $literal;
                }
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!str_starts_with(substr($line, strlen($tag) + 1), 'OK')) {
                    throw new \RuntimeException('IMAP ' . explode(' ', $command)[0] . ' failed: ' . trim($line));
                }

                return $out;
            }
            if (!$collectLiterals) {
                $out[] = rtrim($line, "\r\n");
            }
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new \RuntimeException('IMAP connection closed');
        }

        return $line;
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('IMAP literal truncated');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function quote(string $value): string
    {
        return '"' . addcslashes($value, "\"\\") . '"';
    }
}

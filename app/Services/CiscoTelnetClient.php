<?php

namespace App\Services;

/**
 * Minimal Telnet client for Cisco IOS CLI.
 *
 * Connects, strips IAC option-negotiation bytes, refuses all options,
 * and exposes prompt-based read/write primitives.
 */
class CiscoTelnetClient
{
    private const IAC  = "\xFF";
    private const DONT = "\xFE";
    private const DO   = "\xFD";
    private const WONT = "\xFC";
    private const WILL = "\xFB";

    /** @var resource|null */
    private $sock = null;
    private string $buffer = '';

    public function connect(string $host, int $port = 23, float $timeout = 10.0): void
    {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!$sock) {
            throw new \RuntimeException("Telnet connect to {$host}:{$port} failed: {$errstr} (errno {$errno})");
        }
        stream_set_blocking($sock, true);
        stream_set_timeout($sock, max(1, (int) ceil($timeout)));

        $this->sock = $sock;
        $this->buffer = '';
    }

    public function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
            $this->sock = null;
        }
        $this->buffer = '';
    }

    /**
     * Read until any of the needles appears in the buffer.
     * Returns the consumed portion (up to and including the needle match).
     */
    public function waitFor(array $needles, float $timeout = 10.0): string
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            foreach ($needles as $needle) {
                if ($needle === '') {
                    continue;
                }
                $pos = strpos($this->buffer, $needle);
                if ($pos !== false) {
                    $end = $pos + strlen($needle);
                    $captured = substr($this->buffer, 0, $end);
                    $this->buffer = substr($this->buffer, $end);
                    return $captured;
                }
            }

            if (microtime(true) >= $deadline) {
                throw new \RuntimeException(
                    "Telnet timed out after {$timeout}s waiting for: " . implode(' | ', $needles) .
                    "\n--- captured so far ---\n" . $this->buffer
                );
            }

            $this->readMore();
        }
    }

    /** Wait for a Cisco CLI prompt (`hostname>` or `hostname#`) at end of buffer. */
    public function waitForPrompt(float $timeout = 15.0): string
    {
        $regex = '/(^|\n)[\S]+[>#]\s*$/';
        $deadline = microtime(true) + $timeout;

        while (true) {
            if (preg_match($regex, $this->buffer, $m, PREG_OFFSET_CAPTURE)) {
                $end = $m[0][1] + strlen($m[0][0]);
                $captured = substr($this->buffer, 0, $end);
                $this->buffer = substr($this->buffer, $end);
                return $captured;
            }

            if (microtime(true) >= $deadline) {
                throw new \RuntimeException(
                    "Telnet timed out after {$timeout}s waiting for CLI prompt.\n" .
                    "--- captured so far ---\n" . $this->buffer
                );
            }

            $this->readMore();
        }
    }

    public function send(string $data, bool $appendNewline = true): void
    {
        if (!$this->sock) {
            throw new \RuntimeException('Telnet not connected');
        }
        $payload = $appendNewline ? ($data . "\r\n") : $data;
        $total = strlen($payload);
        $written = 0;

        while ($written < $total) {
            $n = @fwrite($this->sock, substr($payload, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Telnet write failed');
            }
            $written += $n;
        }
    }

    private function readMore(): void
    {
        if (!$this->sock) {
            throw new \RuntimeException('Telnet not connected');
        }

        $chunk = @fread($this->sock, 4096);

        if ($chunk === false) {
            throw new \RuntimeException('Telnet read failed');
        }

        if ($chunk === '') {
            $meta = stream_get_meta_data($this->sock);
            if (!empty($meta['timed_out']) || !empty($meta['eof'])) {
                throw new \RuntimeException('Telnet connection closed or timed out');
            }
            usleep(50_000);
            return;
        }

        $this->buffer .= $this->processIac($chunk);
    }

    /** Strip & respond to IAC option negotiations. Refuse every option. */
    private function processIac(string $chunk): string
    {
        $out = '';
        $len = strlen($chunk);
        $i = 0;

        while ($i < $len) {
            $byte = $chunk[$i];

            if ($byte !== self::IAC) {
                $out .= $byte;
                $i++;
                continue;
            }

            if ($i + 1 >= $len) {
                break;
            }
            $cmd = $chunk[$i + 1];

            if ($cmd === self::IAC) {
                $out .= self::IAC;
                $i += 2;
                continue;
            }

            if (in_array($cmd, [self::DO, self::DONT, self::WILL, self::WONT], true)) {
                if ($i + 2 >= $len) {
                    break;
                }
                $opt = $chunk[$i + 2];

                $reply = match ($cmd) {
                    self::DO   => self::IAC . self::WONT . $opt,
                    self::WILL => self::IAC . self::DONT . $opt,
                    default    => '',
                };
                if ($reply !== '' && $this->sock) {
                    @fwrite($this->sock, $reply);
                }
                $i += 3;
                continue;
            }

            $i += 2;
        }

        return $out;
    }
}

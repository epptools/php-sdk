<?php

declare(strict_types=1);

namespace EppTools;

use EppTools\Exception\ConnectionException;

/**
 * The raw EPP-over-TLS transport: a TLS socket plus RFC 5734 framing (each message is
 * prefixed with a 4-byte big-endian total length that INCLUDES the 4 header bytes).
 * Knows nothing about EPP semantics — it ships and receives byte frames.
 */
final class Connection implements Transport
{
    private const MAX_FRAME = 1_048_576; // 1 MiB guard against a runaway length prefix

    private Config $config;

    /** @var resource|null */
    private $stream = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function open(): void
    {
        $context = stream_context_create($this->config->streamContextOptions());
        $errno = 0;
        $errstr = '';
        // stream_socket_client reports TLS failures (cert verify, refused, timeout) only
        // through a PHP warning, leaving errno/errstr empty — capture that warning so the
        // exception carries the real reason instead of a useless "unknown error (0)".
        $warning = '';
        set_error_handler(static function (int $no, string $message) use (&$warning): bool {
            $warning = preg_replace('~^stream_socket_client\(\):\s*~', '', $message) ?? $message;

            return true;
        });
        try {
            $stream = stream_socket_client(
                sprintf('tls://%s:%d', $this->config->host, $this->config->port),
                $errno,
                $errstr,
                $this->config->connectTimeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            $detail = $errstr !== '' ? $errstr : ($warning !== '' ? $warning : 'unknown error');
            throw new ConnectionException(sprintf(
                'Cannot connect to %s:%d — %s',
                $this->config->host,
                $this->config->port,
                $detail
            ));
        }
        // stream_set_timeout takes whole seconds PLUS microseconds, so the fraction is passed
        // separately: handing it the int part alone truncates a sub-second readTimeout (0.5) to
        // "no timeout at all" and 2.5 s to 2 s. Keep a 1 s floor when the config asks for nothing.
        $readTimeout = (float) $this->config->readTimeout;
        $seconds = (int) $readTimeout;
        $micros = (int) round(($readTimeout - $seconds) * 1_000_000);
        if ($seconds <= 0 && $micros <= 0) {
            $seconds = 1;
            $micros = 0;
        }
        stream_set_timeout($stream, $seconds, $micros);
        $this->stream = $stream;
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream);
    }

    public function writeFrame(string $xml): void
    {
        if (!is_resource($this->stream)) {
            throw new ConnectionException('Not connected');
        }
        $payload = pack('N', strlen($xml) + 4) . $xml;
        $total = strlen($payload);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->stream, substr($payload, $written));
            if ($n === false || $n === 0) {
                throw new ConnectionException($this->timedOut() ? 'Write timed out' : 'Write failed (connection closed?)');
            }
            $written += $n;
        }
    }

    public function readFrame(): string
    {
        $length = unpack('N', $this->readBytes(4))[1];
        if ($length < 4 || $length > self::MAX_FRAME) {
            // TERMINAL, for the same reason a partial read is (see readBytes): the four length bytes
            // are already consumed, so the stream sits at an unknown offset and the next command
            // would read from the middle of this frame. Throwing alone would leave isOpen() true and
            // the connection usable — the desynchronised state this length check exists to catch.
            $this->close();

            throw new ConnectionException("Invalid EPP frame length: {$length}");
        }

        return $this->readBytes($length - 4);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    private function readBytes(int $n): string
    {
        if ($n === 0) {
            return '';
        }
        if (!is_resource($this->stream)) {
            throw new ConnectionException('Not connected');
        }
        $buffer = '';
        while (strlen($buffer) < $n) {
            $chunk = @fread($this->stream, $n - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                // TERMINAL. A partial read leaves the byte stream at an unknown offset, so the NEXT
                // command would read THIS command's response — an off-by-one across billable
                // transforms: renew('b') returns 1000 carrying a's exDate, the registrar books b as
                // renewed, and b's own (also billed) reply lands on whatever comes next.
                //
                // Closing is what makes it terminal: isOpen() is `is_resource($stream)` and
                // Client::transact() gates on it, so throwing alone would leave the connection
                // usable and desynchronised.
                $timedOut = $this->timedOut();
                $this->close();

                throw new ConnectionException($timedOut ? 'Read timed out' : 'Connection closed while reading');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function timedOut(): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        $meta = stream_get_meta_data($this->stream);

        return !empty($meta['timed_out']);
    }
}


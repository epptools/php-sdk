<?php

declare(strict_types=1);

namespace EppTools;

/**
 * The byte-frame transport the Client speaks over. {@see Connection} is the real
 * TLS implementation; tests (or alternative transports) can supply their own.
 */
interface Transport
{
    public function open(): void;

    public function isOpen(): bool;

    public function writeFrame(string $xml): void;

    public function readFrame(): string;

    public function close(): void;
}


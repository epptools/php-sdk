<?php

declare(strict_types=1);

namespace EppTools;

/**
 * A small EPP frame builder over DOMDocument. It guarantees correct child order
 * (command content, then optional <extension>, then <clTRID>) and proper XML escaping
 * (text is added as text nodes, never string-concatenated). Public so callers can
 * assemble bespoke frames for extensions the high-level Client does not cover and send
 * them via Client::request().
 */
final class Frame
{
    private \DOMDocument $doc;
    private \DOMElement $command;
    private ?\DOMElement $extension = null;
    private string $clTRID = '';

    private function __construct()
    {
    }

    /** Start a <command> frame. */
    public static function command(string $clTRID): self
    {
        $frame = new self();
        $frame->doc = new \DOMDocument('1.0', 'UTF-8');
        $frame->doc->formatOutput = false;
        $epp = $frame->doc->createElementNS(Namespaces::EPP, 'epp');
        $frame->doc->appendChild($epp);
        $frame->command = $frame->doc->createElement('command');
        $epp->appendChild($frame->command);
        $frame->clTRID = $clTRID;

        return $frame;
    }

    public function document(): \DOMDocument
    {
        return $this->doc;
    }

    /** Add the command verb element (<check>, <create>, <login>, <poll>, ...). */
    public function verb(string $name): \DOMElement
    {
        $el = $this->doc->createElement($name);
        $this->command->appendChild($el);

        return $el;
    }

    /** Lazily add (once) and return the <extension> element. */
    public function extension(): \DOMElement
    {
        if ($this->extension === null) {
            $this->extension = $this->doc->createElement('extension');
            $this->command->appendChild($this->extension);
        }

        return $this->extension;
    }

    /** Append an element in the base epp-1.0 namespace (no prefix). */
    public function epp(\DOMElement $parent, string $name, ?string $text = null, array $attrs = []): \DOMElement
    {
        return $this->append($this->doc->createElement($name), $parent, $text, $attrs);
    }

    /** Append a namespaced element (e.g. domain:name) carrying its xmlns prefix. */
    public function ns(\DOMElement $parent, string $ns, string $qname, ?string $text = null, array $attrs = []): \DOMElement
    {
        return $this->append($this->doc->createElementNS($ns, $qname), $parent, $text, $attrs);
    }

    public function toXml(): string
    {
        // clTRID is always the FINAL child of <command> (RFC 5730 ordering) — and there is
        // exactly one. toXml() is public, so a caller may serialize the same Frame twice (log it,
        // then send it); any clTRID left by an earlier call is dropped first, because a <command>
        // carrying two of them draws a bare 2001 from the server.
        foreach (iterator_to_array($this->command->childNodes) as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'clTRID') {
                $this->command->removeChild($child);
            }
        }
        $this->epp($this->command, 'clTRID', $this->clTRID);

        return (string) $this->doc->saveXML();
    }

    /** @param array<string, scalar> $attrs */
    private function append(\DOMElement $el, \DOMElement $parent, ?string $text, array $attrs): \DOMElement
    {
        if ($text !== null) {
            $el->appendChild($this->doc->createTextNode($text));
        }
        foreach ($attrs as $name => $value) {
            $el->setAttribute($name, (string) $value);
        }
        $parent->appendChild($el);

        return $el;
    }
}


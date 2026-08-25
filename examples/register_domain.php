<?php

declare(strict_types=1);

/**
 * Create a contact, then register a domain that references it, with nameservers and — where the
 * registry requires one — a trademark licence. Adjust the values before running.
 *
 *   EPP_HOST=... EPP_CLID=... EPP_PASSWORD=... php examples/register_domain.php
 */

$autoload = is_file(__DIR__ . '/../vendor/autoload.php')
    ? __DIR__ . '/../vendor/autoload.php'
    : __DIR__ . '/../autoload.php';
require $autoload;

use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\CommandException;
use EppTools\Exception\EppException;

// EPP_CA is REQUIRED: port 700 presents a certificate from the registry's OWN private CA.
$caFile = getenv('EPP_CA') ?: '';
if ($caFile === '') {
    fwrite(STDERR, "Set EPP_CA to the registry CA certificate: :700 presents a private-CA certificate.\n");
    exit(2);
}

$config = Config::fromArray([
    'host'     => getenv('EPP_HOST') ?: 'epp.registry.example',
    'clid'     => getenv('EPP_CLID') ?: 'EXAMPLE',
    'password' => getenv('EPP_PASSWORD') ?: '',
    'port'     => (int) (getenv('EPP_PORT') ?: 700),
    'caFile'   => $caFile,
]);

// Inside the try: connectAndLogin() reaches the network and authenticates, so it throws on a
// refused connection, a certificate that does not verify, and a bad clID/password. Outside it,
// every one of those became an uncaught fatal with a stack trace instead of the message below.
// Declared first so the finally block can tell "never connected" from "connected and failed".
$client = null;

try {
    $client = Client::connectAndLogin($config);

    // 1. A registrant contact (skip if it already exists — 2302 means "taken").
    try {
        $client->contact()->create('acme-01', [
            'name'     => 'ACME LLC',
            'org'      => 'ACME LLC',
            'street'   => ['1 Khreschatyk St'],
            'city'     => 'Kyiv',
            'pc'       => '01001',
            'cc'       => 'UA',
            'voice'    => '+380.441234567',
            'email'    => 'admin@acme.example',
            'authInfo' => 'C0nt@ct-Pw',
        ]);
        echo "contact acme-01 created\n";
    } catch (CommandException $e) {
        echo "contact acme-01: EPP {$e->eppCode} (already exists?) — continuing\n";
    }

    // 2. The domain. 'license' goes out in the registry's own extension, so it is only accepted
    //    where the registry advertises one — and the client discovers that from the greeting
    //    rather than assuming it.
    $result = $client->domain()->create('your-brand.com.ua', [
        'years'       => 1,
        'registrant'  => 'acme-01',
        'contacts'    => ['admin' => 'acme-01', 'tech' => 'acme-01'],
        'nameservers' => ['ns1.acme.example', 'ns2.acme.example'],
        'authInfo'    => 'D0main-Pw',
        // 'license'  => 'TM-2026-000123',  // <-- where the registry requires a trademark match
    ]);
    echo 'domain create: EPP ' . $result->code()
        . ($result->isPending() ? ' (pending registry approval)' : ' (registered)') . "\n";
    echo 'expires: ' . ($result->value('exDate') ?? '-') . "\n";

    $client->logout();
} catch (EppException $e) {
    // STDERR exists only on the CLI; under php-fpm write to the response instead.
    fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb'), 'EPP error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $client?->disconnect();
}







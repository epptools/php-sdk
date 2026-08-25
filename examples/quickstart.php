<?php

declare(strict_types=1);

/**
 * Connect, log in, check availability, read a domain, log out.
 *
 *   EPP_HOST=epp.registry.example EPP_CLID=EXAMPLE EPP_PASSWORD=secret \
 *   php examples/quickstart.php
 *
 * This is a COMMAND-LINE example — run it with `php`, not via a web request. For a web
 * app (php-fpm), use the SDK classes directly and echo your own output: see web.php.
 *
 * EPP_CA is REQUIRED: port 700 presents a certificate from the registry's OWN private CA, which
 * no system trust store carries, so the handshake fails without it.
 */

$autoload = is_file(__DIR__ . '/../vendor/autoload.php')
    ? __DIR__ . '/../vendor/autoload.php'   // Composer
    : __DIR__ . '/../autoload.php';          // no-Composer fallback
require $autoload;

use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\EppException;
$caFile = getenv('EPP_CA') ?: '';
if ($caFile === '') {
    fwrite(STDERR, "Set EPP_CA to the registry CA certificate: :700 presents a private-CA certificate.\n");
    exit(2);
}

$config = Config::fromArray([
    'host'     => getenv('EPP_HOST') ?: 'epp.registry.example',
    'clid'     => getenv('EPP_CLID') ?: 'EXAMPLE',
    'password' => getenv('EPP_PASSWORD') ?: '',
    'port'     => (int) (getenv('EPP_PORT') ?: 700),  // default 700; override if needed
    'caFile'   => $caFile,                            // CA bundle of the registry's private CA
    // Only if your endpoint requires mutual TLS (the public :700 endpoint does not):
    // 'clientCert'          => '/path/registrar.crt.pem',
    // 'clientKey'           => '/path/registrar.key.pem',
    // 'clientKeyPassphrase' => 'key-passphrase-if-encrypted',
]);

// Optional: pass any PSR-3 logger (e.g. Monolog) — passwords/authInfo are masked.
//   $log = new Monolog\Logger('epp'); $log->pushHandler(new Monolog\Handler\StreamHandler('php://stderr'));
//   $client = new Client($config, null, $log);

$client = new Client($config);

try {
    $client->connect();          // opens TLS and reads the <greeting>
    $client->login();            // advertises exactly the services the server offers

    $availability = $client->domain()->check(['example.com.ua', 'your-brand.com.ua'])->availability();
    foreach ($availability as $name => $free) {
        echo sprintf("%-24s %s\n", $name, $free ? 'available' : 'taken');
    }

    $info = $client->domain()->info('example.com.ua');
    echo "\nexample.com.ua\n";
    // statuses(), not values('status'): a status is carried in the `s` ATTRIBUTE, so the element
    // text is empty and values('status') printed a row of blanks.
    echo '  status:  ' . implode(', ', $info->statuses() ?: ['-']) . "\n";
    echo '  expires: ' . ($info->value('exDate') ?? '-') . "\n";
    // Nameservers live in <domain:hostObj>; values('ns') would fuse them into one string.
    echo '  ns:      ' . implode(', ', $info->values('hostObj') ?: ['-']) . "\n";

    $balance = $client->balance();
    echo "\nbalance: " . ($balance->value('balance') ?? '-') . "\n";

    $client->logout();
} catch (EppException $e) {
    // STDERR exists only on the CLI; under php-fpm write to the response instead.
    fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb'), 'EPP error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $client->disconnect();
}







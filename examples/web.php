<?php

declare(strict_types=1);

/**
 * Minimal WEB (php-fpm) integration: a JSON endpoint that checks domain availability.
 * Unlike quickstart.php / register_domain.php (which are CLI scripts), this one is
 * meant to be served over HTTP — it emits JSON and never touches STDERR/exit.
 *
 *   GET /web.php?names=example.com.ua,your-brand.com.ua
 *
 * IMPORTANT: never hardcode real credentials in a web-served file — load them from your
 * framework config / environment (here: env vars set in the php-fpm pool or server).
 */

require __DIR__ . '/../vendor/autoload.php'; // or, without Composer: require __DIR__ . '/../autoload.php';

use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\EppException;

header('Content-Type: application/json; charset=utf-8');

$names = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($_GET['names'] ?? 'example.com.ua')),
)));

// EPP_CA is REQUIRED: port 700 presents a certificate from the registry's OWN private CA, which
// no system trust store carries — without it every request would fail the handshake.
$caFile = getenv('EPP_CA') ?: '';
if ($caFile === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'EPP_CA is not set (the registry CA certificate)']);

    return;
}

$config = Config::fromArray([
    'host'     => getenv('EPP_HOST') ?: 'epp.registry.example',
    'clid'     => getenv('EPP_CLID') ?: 'EXAMPLE',
    'password' => getenv('EPP_PASSWORD') ?: '',     // set in your php-fpm pool / app secrets
    'caFile'   => $caFile,                     // CA bundle of the registry's private CA
]);

try {
    $client = new Client($config);
    $client->connect();
    $client->login();
    $availability = $client->domain()->check($names)->availability();
    $client->logout();
    $client->disconnect();

    echo json_encode(['ok' => true, 'availability' => $availability], JSON_UNESCAPED_UNICODE);
} catch (EppException $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}







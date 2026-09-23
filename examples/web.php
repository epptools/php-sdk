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
// `?names=` — present but empty, or nothing but commas — filters down to no names at all, and
// domain:check requires at least one <domain:name>: the library refuses the call, which would surface
// here as a 502 about the registry for what is really a bad request. Answer it as one, before opening
// a socket.
if ($names === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'names is empty — give at least one domain name']);

    return;
}

// EPP_CA points at the CA bundle that signed the SERVER certificate. Needed where the registry
// runs its own CA, whose certificate no system trust store carries; against a browser-trusted
// certificate there is nothing to set and this guard can go.
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







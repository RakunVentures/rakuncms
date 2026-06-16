<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\Inspector;

/**
 * Live integration test against a real Plesk Obsidian sandbox (REST-only).
 *
 * Since #24-#26 we no longer have an XML-RPC fallback path, so the previous
 * skip-on-PleskTransportException / skip-on-PleskEndpointNotFoundException
 * pattern (which covered "REST v2 disabled, retry over XML-RPC") is gone.
 * The test suite now fails loudly when REST cannot reach the sandbox —
 * that is the contract for Plesk Obsidian 18.0.78+.
 *
 * SKIP CONDITION: All three env vars MUST be set or the entire file is skipped.
 *   PLESK_TEST_HOST     — e.g. plesk.example.com  (no scheme, no port)
 *   PLESK_TEST_API_KEY  — admin-level API key
 *   PLESK_TEST_DOMAIN   — a domain known to exist on the sandbox
 *
 * Optional:
 *   PLESK_TEST_VERIFY_SSL=1 — enable SSL verification (default: off for self-signed sandboxes)
 *
 * Manual run:
 *   PLESK_TEST_HOST=plesk.example.com \
 *   PLESK_TEST_API_KEY=xxxx \
 *   PLESK_TEST_DOMAIN=mysite.example.com \
 *   herd php vendor/bin/pest tests/Integration/Deploy/PleskApi/PleskSandboxTest.php
 */

$pleskHost   = getenv('PLESK_TEST_HOST') ?: '';
$pleskApiKey = getenv('PLESK_TEST_API_KEY') ?: '';
$pleskDomain = getenv('PLESK_TEST_DOMAIN') ?: '';

if ($pleskHost === '' || $pleskApiKey === '' || $pleskDomain === '') {
    test('PleskApi sandbox — skipped (env vars missing)', function () {
        $this->markTestSkipped(
            'PLESK_TEST_HOST, PLESK_TEST_API_KEY and PLESK_TEST_DOMAIN must all be set. '
            . 'These tests are intended to run against a real Plesk Obsidian sandbox.'
        );
    });
    return;
}

$verifySsl = (getenv('PLESK_TEST_VERIFY_SSL') === '1');

$GLOBALS['plesk_host']      = $pleskHost;
$GLOBALS['plesk_api_key']   = $pleskApiKey;
$GLOBALS['plesk_domain']    = $pleskDomain;
$GLOBALS['plesk_verify_ssl'] = $verifySsl;

it('PleskSandbox: Client can reach Plesk and authenticate (REST server endpoint)', function () {
    $client = new Client(
        $GLOBALS['plesk_host'],
        $GLOBALS['plesk_api_key'],
        $GLOBALS['plesk_verify_ssl'],
        timeout: 15,
    );

    // REST is the only supported transport on Plesk 18.0.78+ — failure here
    // means the sandbox is misconfigured, not that we should silently skip.
    // `server` is the canonical info endpoint (REST v2). `server/ip` does
    // not exist (returns 404 in REST v2 — verified against live sandbox).
    $response = $client->restGet('server');

    expect($response)->toBeArray();
});

it('PleskSandbox: TRIPWIRE — restGet(domains) reaches Plesk without silent-catch', function () {
    $client = new Client(
        $GLOBALS['plesk_host'],
        $GLOBALS['plesk_api_key'],
        $GLOBALS['plesk_verify_ssl'],
        timeout: 15,
    );

    // Inspector internamente captura PleskApiException y cae a fallbacks
    // (/httpdocs, null git, null php, null shell). Esto es comportamiento
    // intencional en producción (un Plesk parcial no debe abortar discovery),
    // pero en la suite de integración produce "green-by-fallback": los 5
    // tests Inspector de abajo pasan aunque Plesk esté roto.
    //
    // Este tripwire llama directo al endpoint que Inspector usa por dentro
    // (`domains`), SIN silent-catch — cuando falla, los verdes del Inspector
    // ya no son señal confiable. Auth/rate-limit problems del sandbox quedan
    // visibles aquí en lugar de enmascarados.
    $domains = $client->restGet('domains');

    $list = $domains['data'] ?? $domains;
    expect($list)->toBeArray()->not->toBeEmpty();
});

it('PleskSandbox: Inspector.discover() returns the expected shape for a known domain', function () {
    $client = new Client(
        $GLOBALS['plesk_host'],
        $GLOBALS['plesk_api_key'],
        $GLOBALS['plesk_verify_ssl'],
        timeout: 30,
    );
    $inspector = new Inspector($client);

    $result = $inspector->discover($GLOBALS['plesk_domain']);

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['domain', 'has_shell', 'git', 'php', 'doc_root', 'discovered_at'])
        ->and($result['domain'])->toBe($GLOBALS['plesk_domain'])
        ->and($result['doc_root'])->toBeString()
        ->and($result['doc_root'])->not->toBeEmpty();
});

it('PleskSandbox: Inspector.getDocumentRoot() returns a path starting with /', function () {
    $client = new Client(
        $GLOBALS['plesk_host'],
        $GLOBALS['plesk_api_key'],
        $GLOBALS['plesk_verify_ssl'],
        timeout: 15,
    );
    $inspector = new Inspector($client);

    $root = $inspector->getDocumentRoot($GLOBALS['plesk_domain']);

    expect($root)->toBeString()
        ->and($root)->not->toBeEmpty()
        ->and(str_starts_with($root, '/'))->toBeTrue();
});

it('PleskSandbox: Inspector.hasShellAccess() returns bool or null without exception', function () {
    $client = new Client(
        $GLOBALS['plesk_host'],
        $GLOBALS['plesk_api_key'],
        $GLOBALS['plesk_verify_ssl'],
        timeout: 15,
    );
    $inspector = new Inspector($client);

    $shell = $inspector->hasShellAccess($GLOBALS['plesk_domain']);

    expect($shell === null || is_bool($shell))->toBeTrue();
});

it('PleskSandbox: Inspector.getPhpInfo() returns null or {version, handler}', function () {
    $client = new Client(
        $GLOBALS['plesk_host'],
        $GLOBALS['plesk_api_key'],
        $GLOBALS['plesk_verify_ssl'],
        timeout: 15,
    );
    $inspector = new Inspector($client);

    $php = $inspector->getPhpInfo($GLOBALS['plesk_domain']);

    if ($php !== null) {
        expect($php)->toHaveKeys(['version', 'handler'])
            ->and($php['version'])->toBeString()
            ->and($php['handler'])->toBeString();
    } else {
        expect($php)->toBeNull();
    }
});

it('PleskSandbox: Inspector.getGitInfo() returns null or full repo info', function () {
    $client = new Client(
        $GLOBALS['plesk_host'],
        $GLOBALS['plesk_api_key'],
        $GLOBALS['plesk_verify_ssl'],
        timeout: 30,
    );
    $inspector = new Inspector($client);

    $git = $inspector->getGitInfo($GLOBALS['plesk_domain']);

    if ($git !== null) {
        expect($git)->toHaveKeys(['repo_name', 'webhook_url', 'active_branch', 'deploy_path'])
            ->and($git['repo_name'])->toBeString()
            ->and($git['repo_name'])->not->toBeEmpty();
    } else {
        expect($git)->toBeNull();
    }
});

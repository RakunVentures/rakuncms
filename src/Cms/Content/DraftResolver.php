<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Vista previa de entradas NO publicadas (o de la versión en BD de una
 * publicada). Resuelve la entrada desde la FUENTE DE VERDAD (ContentStorage:
 * MySQL en sitios gestionados, .md en flat-file), SIN filtrar por status, y
 * firma/valida enlaces de preview compartibles.
 *
 * Token: firmado (HMAC-SHA256), por-entrada y expirable, para poder mandar el
 * enlace al cliente sin login pero sin que sea adivinable ni reutilizable para
 * otra entrada. Compat: un `preview.token` global de config sigue valiendo
 * (debug/MVP), pero sin scope.
 */
final class DraftResolver
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    // ── Token firmado ────────────────────────────────────────────────────────

    /** Firma un token de preview para una entrada concreta. */
    public function signToken(string $collection, string $locale, string $slug, ?int $now = null): string
    {
        $now ??= time();
        $payload = ['c' => $collection, 'l' => $locale, 's' => $slug, 'exp' => $now + $this->ttl()];
        $b64 = $this->b64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $b64 . '.' . hash_hmac('sha256', $b64, $this->secret());
    }

    /** Epoch en que expira un token firmado emitido "ahora". */
    public function expiresAt(?int $now = null): int
    {
        return ($now ?? time()) + $this->ttl();
    }

    /**
     * Valida un token. Para un token FIRMADO válido y vigente devuelve la
     * identidad de la entrada {collection, locale, slug}. Para el token GLOBAL
     * legacy válido devuelve ['global' => true] (sin scope: el caller usa la URL).
     * Null si es inválido o expiró.
     *
     * @return array{collection?: string, locale?: string, slug?: string, global?: bool}|null
     */
    public function verifyToken(string $token, ?int $now = null): ?array
    {
        if ($token === '') {
            return null;
        }
        $now ??= time();

        // Token global legacy (config preview.token).
        $legacy = $this->legacyToken();
        if ($legacy !== '' && hash_equals($legacy, $token)) {
            return ['global' => true];
        }

        // Token firmado: base64url(payload).hmac
        if (!str_contains($token, '.')) {
            return null;
        }
        $secret = $this->secret();
        if ($secret === '') {
            return null;
        }
        [$b64, $sig] = explode('.', $token, 2);
        if (!hash_equals(hash_hmac('sha256', $b64, $secret), $sig)) {
            return null;
        }
        $payload = json_decode($this->b64UrlDecode($b64), true);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < $now) {
            return null;
        }

        return [
            'collection' => (string) ($payload['c'] ?? ''),
            'locale' => (string) ($payload['l'] ?? ''),
            'slug' => (string) ($payload['s'] ?? ''),
        ];
    }

    /** ¿Hay configuración de preview (secreto firmado o token legacy)? */
    public function isConfigured(): bool
    {
        return $this->secret() !== '' || $this->legacyToken() !== '';
    }

    // ── Resolución de la entrada desde la fuente de verdad ───────────────────

    /**
     * Lee la entrada desde el ContentStorage (MySQL/SSoT o .md) sin filtrar por
     * status, e inyecta el cuerpo renderizado desde la fuente de verdad. Devuelve
     * null si no existe en el store.
     */
    public function resolveEntry(string $collection, string $locale, string $slug): ?Entry
    {
        $storage = ContentStorageFactory::make($this->basePath);

        $body = $storage->read($collection, $locale, $slug);
        if ($body === null) {
            // El slug puede no ser la clave de storage (WXR usa full_slug). Buscar
            // por la enumeración del store y reintentar con su clave canónica.
            foreach ($storage->listKeys() as $ref) {
                if ($ref->collection === $collection && $ref->slug === $slug) {
                    $body = $storage->read($ref->collection, $ref->locale, $ref->slug);
                    $locale = $ref->locale;
                    break;
                }
            }
        }
        if ($body === null) {
            return null;
        }

        $fm = $body->frontmatter;
        $entry = Entry::fromArray([
            'title' => $fm['title'] ?? $slug,
            'slug' => $slug,
            'collection' => $collection,
            'locale' => $locale,
            'file' => $body->file,
            'template' => $fm['template'] ?? null,
            'date' => isset($fm['date']) ? (string) $fm['date'] : null,
            'order' => (int) ($fm['order'] ?? 0),
            'draft' => ($fm['status'] ?? '') === 'draft' || !empty($fm['draft']),
            'meta' => $fm,
            'slugs' => is_array($fm['slugs'] ?? null) ? $fm['slugs'] : [],
            'mtime' => time(),
        ]);

        // Cuerpo desde la fuente de verdad (no del .md cache).
        return $entry->preloadContent((new Parser())->renderString($body->body));
    }

    // ── Banner ───────────────────────────────────────────────────────────────

    /** Envuelve el HTML con un banner de vista previa (no publicado). */
    public function injectDraftBanner(string $html, string $status = ''): string
    {
        $label = 'VISTA PREVIA — no publicado';
        if ($status !== '') {
            $label .= ' · estado: ' . htmlspecialchars($status, ENT_QUOTES);
        }
        $banner = '<div style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#f59e0b;color:#000;text-align:center;padding:8px 16px;font-family:system-ui,sans-serif;font-weight:bold;font-size:14px;">'
            . $label . '</div>';

        if (preg_match('/<body[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1] + strlen($matches[0][0]);

            return substr($html, 0, $pos) . $banner . substr($html, $pos);
        }

        return $banner . $html;
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function secret(): string
    {
        $s = $this->config('preview.secret') ?? $this->config('rakun.preview.secret');

        return is_string($s) ? $s : '';
    }

    private function legacyToken(): string
    {
        $t = $this->config('preview.token') ?? $this->config('rakun.preview.token');

        return is_string($t) ? $t : '';
    }

    private function ttl(): int
    {
        $t = $this->config('preview.ttl') ?? $this->config('rakun.preview.ttl');

        return is_numeric($t) ? max(60, (int) $t) : 604800; // 7 días por defecto
    }

    private function config(string $key): mixed
    {
        if (!function_exists('config')) {
            return null;
        }
        try {
            return \config($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $b64): string
    {
        return (string) base64_decode(strtr($b64, '-_', '+/'), true);
    }
}

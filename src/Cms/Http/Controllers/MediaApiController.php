<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MediaApiController
{
    /** Tope por chunk individual. Holgura sobre los ~1.5 MB que envía el cliente. */
    private const MAX_CHUNK_BYTES = 4 * 1024 * 1024; // 4 MB

    /** Tope del archivo ensamblado (anti-DoS). 100 MB de revista + margen. */
    private const MAX_UPLOAD_BYTES = 150 * 1024 * 1024; // 150 MB

    /** TTL de las sesiones de chunks abandonadas. */
    private const CHUNK_TTL_SECONDS = 86400; // 24 h

    private string $basePath;
    private string $assetsDir;

    /** @var array<string, list<string>> */
    private array $allowedMimeTypes = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
        'application/pdf' => ['pdf'],
        'video/mp4' => ['mp4'],
        'video/quicktime' => ['mov'],
        'video/webm' => ['webm']
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->assetsDir = $basePath . '/public/assets';
    }

    public function list(): ResponseInterface
    {
        $files = [];
        if (is_dir($this->assetsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->assetsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $relativePath = str_replace($this->assetsDir . '/', '', $file->getPathname());
                $files[] = [
                    'path' => 'assets/' . $relativePath,
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                    'type' => $this->mimeFromExtension($file->getExtension()),
                ];
            }
        }
        return $this->json(200, ['data' => $files, 'meta' => ['count' => count($files)]]);
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json(400, ['error' => 'No file uploaded or upload error']);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'rkn_upload');
        file_put_contents($tempPath, (string) $file->getStream());

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tempPath);
        finfo_close($finfo);

        if (!isset($this->allowedMimeTypes[$mimeType])) {
            @unlink($tempPath);
            return $this->json(415, ['error' => "MIME type $mimeType not allowed"]);
        }

        // Safe subdirectory (no traversal) and safe filename. The extension is
        // derived from the VERIFIED MIME type, not the client-supplied name.
        $body   = $request->getParsedBody();
        $subDir = is_array($body) && isset($body['directory']) ? (string) $body['directory'] : 'uploads';
        $subDir = $this->sanitizeSubDir($subDir);

        $originalName = $file->getClientFilename() ?? 'upload';
        $stem = $this->sanitizeStem(pathinfo($originalName, PATHINFO_FILENAME));
        $ext  = $this->allowedMimeTypes[$mimeType][0];

        $data = $this->moveToAssets($tempPath, $stem, $ext, $subDir, $mimeType);

        return $this->json(201, ['data' => $data, 'message' => 'File uploaded']);
    }

    /**
     * Recibe un trozo (chunk) de una subida grande. Mismo origen → el admin lo
     * reenvía; cada request es chico (cabe en cualquier post_max_size por defecto),
     * así que NO requiere tocar el servidor. Los chunks se anexan a disco por índice
     * en una carpeta scratch (fuera de public/), y finalize() los ensambla.
     *
     * Body (multipart): upload_id (hex32), chunk_index (>=0), chunk (archivo).
     */
    public function chunk(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uploadId = is_array($body) ? (string) ($body['upload_id'] ?? '') : '';
        if (!$this->isValidUploadId($uploadId)) {
            return $this->json(400, ['error' => 'Invalid upload_id']);
        }

        $indexRaw = is_array($body) ? ($body['chunk_index'] ?? null) : null;
        if (!is_numeric($indexRaw) || (int) $indexRaw < 0) {
            return $this->json(400, ['error' => 'Invalid chunk_index']);
        }
        $index = (int) $indexRaw;

        $chunk = $request->getUploadedFiles()['chunk'] ?? null;
        if ($chunk === null || $chunk->getError() !== UPLOAD_ERR_OK) {
            return $this->json(400, ['error' => 'No chunk uploaded or upload error']);
        }

        $this->sweepStaleChunks();

        $dir = $this->chunksBaseDir() . '/' . $uploadId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Idempotente: un reintento del mismo índice sobreescribe el .part.
        $partPath = $dir . '/' . $index . '.part';
        file_put_contents($partPath, (string) $chunk->getStream());

        // Tope por chunk y total acumulado (anti-DoS), con tamaños reales en disco.
        if ((int) (filesize($partPath) ?: 0) > self::MAX_CHUNK_BYTES) {
            @unlink($partPath);
            return $this->json(413, ['error' => 'Chunk too large']);
        }
        $total = 0;
        foreach (glob($dir . '/*.part') ?: [] as $p) {
            $total += (int) (filesize($p) ?: 0);
        }
        if ($total > self::MAX_UPLOAD_BYTES) {
            @unlink($partPath);
            return $this->json(413, ['error' => 'Total upload size exceeded']);
        }

        return $this->json(201, ['data' => [
            'upload_id'      => $uploadId,
            'chunk_index'    => $index,
            'bytes_received' => (int) (filesize($partPath) ?: 0),
        ]]);
    }

    /**
     * Ensambla los chunks de una sesión, valida el MIME del archivo COMPLETO y lo
     * mueve a assets/. El ensamblado es por stream (nunca carga el archivo entero
     * en memoria). Mismo shape de respuesta que upload().
     *
     * Body: upload_id, total_chunks, filename, directory.
     */
    public function finalize(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uploadId = is_array($body) ? (string) ($body['upload_id'] ?? '') : '';
        if (!$this->isValidUploadId($uploadId)) {
            return $this->json(400, ['error' => 'Invalid upload_id']);
        }
        $total = is_array($body) ? (int) ($body['total_chunks'] ?? 0) : 0;
        if ($total < 1) {
            return $this->json(400, ['error' => 'Invalid total_chunks']);
        }
        $subDir = is_array($body) && isset($body['directory'])
            ? $this->sanitizeSubDir((string) $body['directory'])
            : 'uploads';
        $originalName = is_array($body) ? (string) ($body['filename'] ?? 'upload') : 'upload';

        $dir      = $this->chunksBaseDir() . '/' . $uploadId;
        $realDir  = realpath($dir);
        $realBase = realpath($this->chunksBaseDir());
        if ($realDir === false || $realBase === false || !str_starts_with($realDir, $realBase)) {
            return $this->json(404, ['error' => 'Upload session not found']);
        }

        $missing = [];
        for ($i = 0; $i < $total; $i++) {
            if (!is_file($dir . '/' . $i . '.part')) {
                $missing[] = $i;
            }
        }
        if ($missing !== []) {
            return $this->json(409, ['error' => 'Missing chunks', 'missing' => $missing]);
        }

        // Ensamblar por stream → nunca todo el archivo en RAM.
        $assembled = $dir . '/assembled.tmp';
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            $this->removeDir($dir);
            return $this->json(500, ['error' => 'Cannot assemble upload']);
        }
        for ($i = 0; $i < $total; $i++) {
            $in = fopen($dir . '/' . $i . '.part', 'rb');
            if ($in === false) {
                fclose($out);
                $this->removeDir($dir);
                return $this->json(500, ['error' => 'Cannot read chunk']);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $assembled);
        finfo_close($finfo);
        if (!isset($this->allowedMimeTypes[$mimeType])) {
            $this->removeDir($dir);
            return $this->json(415, ['error' => "MIME type $mimeType not allowed"]);
        }

        $stem = $this->sanitizeStem(pathinfo($originalName, PATHINFO_FILENAME));
        $ext  = $this->allowedMimeTypes[$mimeType][0];

        $data = $this->moveToAssets($assembled, $stem, $ext, $subDir, $mimeType);

        $this->removeDir($dir);

        return $this->json(201, ['data' => $data, 'message' => 'File uploaded']);
    }

    /**
     * Cheap extension→MIME lookup for listing. Avoids mime_content_type(), which
     * reads each file's magic bytes and turns a large asset tree into a multi-second
     * (or timing-out) scan. For a directory listing the stored extension is a
     * sufficient, stable type hint; upload still verifies the real MIME by finfo.
     */
    private function mimeFromExtension(string $ext): string
    {
        static $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'pdf'  => 'application/pdf',
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'webm' => 'video/webm',
            'txt'  => 'text/plain',
            'json' => 'application/json',
            'css'  => 'text/css',
            'js'   => 'text/javascript',
        ];

        return $map[strtolower($ext)] ?? 'application/octet-stream';
    }

    private function sanitizeSubDir(string $dir): string
    {
        $parts = [];
        foreach (explode('/', $dir) as $segment) {
            $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $segment) ?? '';
            if ($clean !== '' && $clean !== '.' && $clean !== '..') {
                $parts[] = $clean;
            }
        }

        return $parts === [] ? 'uploads' : implode('/', $parts);
    }

    private function sanitizeStem(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');

        return $name === '' ? 'file' : substr($name, 0, 80);
    }

    private function uniqueFilename(string $dir, string $stem, string $ext): string
    {
        $candidate = "{$stem}.{$ext}";
        $i = 1;
        while (file_exists($dir . '/' . $candidate)) {
            $candidate = "{$stem}-{$i}.{$ext}";
            $i++;
        }

        return $candidate;
    }

    /**
     * Mueve un archivo temporal a assets/{subDir}/ con nombre único, lo deja
     * world-readable (0644) y arma la respuesta. Compartido por upload() y
     * finalize() (DRY).
     *
     * @return array<string, mixed>
     */
    private function moveToAssets(string $tmpPath, string $stem, string $ext, string $subDir, string $mimeType): array
    {
        $targetDir = $this->assetsDir . '/' . $subDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename   = $this->uniqueFilename($targetDir, $stem, $ext);
        $targetPath = $targetDir . '/' . $filename;
        rename($tmpPath, $targetPath);

        // En Plesk+nginx los estáticos se sirven como otro usuario; 0644 evita 403.
        @chmod($targetPath, 0644);

        $width = null;
        $height = null;
        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            $dims = @getimagesize($targetPath);
            if (is_array($dims)) {
                $width  = $dims[0];
                $height = $dims[1];
            }
        }

        $relative = $subDir . '/' . $filename;

        return [
            'url'    => '/assets/' . $relative,
            'path'   => 'assets/' . $relative,
            'mime'   => $mimeType,
            'size'   => filesize($targetPath) ?: 0,
            'width'  => $width,
            'height' => $height,
        ];
    }

    /** Carpeta scratch de chunks, fuera de public/ (mismo volumen → rename EXDEV-safe). */
    private function chunksBaseDir(): string
    {
        return $this->basePath . '/storage/uploads/chunks';
    }

    /** uploadId generado por el cliente: 32 hex aleatorios. Bloquea traversal por construcción. */
    private function isValidUploadId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** Borra sesiones de chunks abandonadas (best-effort, oportunista en cada chunk). */
    private function sweepStaleChunks(): void
    {
        $base = $this->chunksBaseDir();
        if (!is_dir($base)) {
            return;
        }
        $cutoff = time() - self::CHUNK_TTL_SECONDS;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ((int) (@filemtime($dir) ?: 0) < $cutoff) {
                $this->removeDir($dir);
            }
        }
    }

    public function delete(string $mediaPath): ResponseInterface
    {
        $realBase = realpath($this->basePath . '/public') ?: '';
        $targetPath = $this->basePath . '/public/' . $mediaPath;
        $realTarget = realpath($targetPath);
        if ($realTarget === false || !str_starts_with($realTarget, $realBase)) {
            return $this->json(400, ['error' => 'Invalid path']);
        }
        if (!file_exists($realTarget)) {
            return $this->json(404, ['error' => 'File not found']);
        }
        unlink($realTarget);
        return $this->json(200, ['message' => 'File deleted']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data) ?: '{}');
    }
}

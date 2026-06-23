<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Nyholm\Psr7\Uri;
use Rkn\Cms\Http\Controllers\MediaApiController;

/**
 * Subida por chunks (archivos grandes en hosting con límites chicos): cada chunk
 * es un request chico que se anexa a disco; finalize() ensambla por stream, valida
 * el MIME del archivo COMPLETO y lo mueve a assets/. Sin cargar el archivo en RAM.
 */

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-chunk-test-' . uniqid();
    mkdir($this->tempDir . '/public/assets', 0755, true);
    $this->controller = new MediaApiController($this->tempDir);
    $this->chunksDir  = $this->tempDir . '/storage/uploads/chunks';

    // PDF mínimo que finfo detecta como application/pdf.
    $this->pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $this->uploadId = bin2hex(random_bytes(16)); // 32 hex
});

afterEach(function () {
    $cleanup = function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) return;
        foreach (new DirectoryIterator($dir) as $item) {
            if ($item->isDot()) continue;
            $item->isDir() ? $cleanup($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    };
    $cleanup($this->tempDir);
});

function sendChunk(MediaApiController $c, string $uploadId, int $index, string $bytes): array
{
    $upload = new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, 'chunk', 'application/octet-stream');
    $req = (new ServerRequest('POST', new Uri('/api/v1/media/chunk')))
        ->withParsedBody(['upload_id' => $uploadId, 'chunk_index' => $index])
        ->withUploadedFiles(['chunk' => $upload]);
    $res = $c->chunk($req);

    return [$res->getStatusCode(), json_decode((string) $res->getBody(), true)];
}

function finalizeUpload(MediaApiController $c, string $uploadId, int $total, string $filename = 'revista.pdf', string $dir = 'uploads', string $onConflict = 'increment'): array
{
    $req = (new ServerRequest('POST', new Uri('/api/v1/media/finalize')))
        ->withParsedBody([
            'upload_id'    => $uploadId,
            'total_chunks' => $total,
            'filename'     => $filename,
            'directory'    => $dir,
            'on_conflict'  => $onConflict,
        ]);
    $res = $c->finalize($req);

    return [$res->getStatusCode(), json_decode((string) $res->getBody(), true)];
}

// ── chunk() ──────────────────────────────────────────────────────────────────

test('chunk guarda el .part por índice', function () {
    [$status, $payload] = sendChunk($this->controller, $this->uploadId, 0, 'hello');

    expect($status)->toBe(201);
    expect($payload['data']['bytes_received'])->toBe(5);
    expect(file_exists($this->chunksDir . '/' . $this->uploadId . '/0.part'))->toBeTrue();
});

test('chunk es idempotente: reenviar el mismo índice sobreescribe (un solo archivo)', function () {
    sendChunk($this->controller, $this->uploadId, 0, 'primera');
    sendChunk($this->controller, $this->uploadId, 0, 'segunda');

    $parts = glob($this->chunksDir . '/' . $this->uploadId . '/*.part');
    expect(count($parts))->toBe(1);
    expect(file_get_contents($parts[0]))->toBe('segunda');
});

test('chunk rechaza upload_id inválido', function () {
    [$status] = sendChunk($this->controller, 'NO-es-hex', 0, 'x');
    expect($status)->toBe(400);
});

test('chunk rechaza chunk_index negativo', function () {
    [$status] = sendChunk($this->controller, $this->uploadId, -1, 'x');
    expect($status)->toBe(400);
});

test('chunk rechaza request sin archivo', function () {
    $req = (new ServerRequest('POST', new Uri('/api/v1/media/chunk')))
        ->withParsedBody(['upload_id' => $this->uploadId, 'chunk_index' => 0]);
    $res = $this->controller->chunk($req);
    expect($res->getStatusCode())->toBe(400);
});

// ── finalize() ───────────────────────────────────────────────────────────────

test('finalize ensambla los chunks y devuelve la URL del PDF', function () {
    $half = (int) ceil(strlen($this->pdf) / 2);
    sendChunk($this->controller, $this->uploadId, 0, substr($this->pdf, 0, $half));
    sendChunk($this->controller, $this->uploadId, 1, substr($this->pdf, $half));

    [$status, $payload] = finalizeUpload($this->controller, $this->uploadId, 2);

    expect($status)->toBe(201);
    expect($payload['data']['mime'])->toBe('application/pdf');
    expect($payload['data']['path'])->toMatch('#^assets/uploads/[a-z0-9-]+\.pdf$#');

    $full = $this->tempDir . '/public/' . $payload['data']['path'];
    expect(file_exists($full))->toBeTrue();
    // El archivo reconstruido es idéntico al original.
    expect(file_get_contents($full))->toBe($this->pdf);
    // 0644 (servible en Plesk+nginx).
    expect(fileperms($full) & 0777)->toBe(0644);
});

test('finalize rechaza un MIME no permitido (415)', function () {
    $text = "esto es texto plano, no un pdf ni imagen";
    sendChunk($this->controller, $this->uploadId, 0, $text);

    [$status] = finalizeUpload($this->controller, $this->uploadId, 1, 'note.txt');
    expect($status)->toBe(415);
});

test('finalize devuelve 409 con los chunks faltantes', function () {
    sendChunk($this->controller, $this->uploadId, 0, 'a');
    sendChunk($this->controller, $this->uploadId, 2, 'c'); // falta el 1

    [$status, $payload] = finalizeUpload($this->controller, $this->uploadId, 3);
    expect($status)->toBe(409);
    expect($payload['missing'])->toBe([1]);
});

test('finalize limpia el directorio temporal tras ensamblar', function () {
    $half = (int) ceil(strlen($this->pdf) / 2);
    sendChunk($this->controller, $this->uploadId, 0, substr($this->pdf, 0, $half));
    sendChunk($this->controller, $this->uploadId, 1, substr($this->pdf, $half));
    finalizeUpload($this->controller, $this->uploadId, 2);

    expect(is_dir($this->chunksDir . '/' . $this->uploadId))->toBeFalse();
});

test('finalize devuelve 404 si la sesión no existe', function () {
    [$status] = finalizeUpload($this->controller, bin2hex(random_bytes(16)), 1);
    expect($status)->toBe(404);
});

test('finalize rechaza upload_id con formato inválido (400)', function () {
    [$status] = finalizeUpload($this->controller, '../../etc', 1);
    expect($status)->toBe(400);
});

// ── on_conflict (nombre por plantilla) ───────────────────────────────────────

test('finalize on_conflict=error preserva mayúsculas y subdir', function () {
    sendChunk($this->controller, $this->uploadId, 0, $this->pdf);

    [$status, $payload] = finalizeUpload(
        $this->controller, $this->uploadId, 1,
        '2025-Revista-Digital-DICIEMBRE-2025', 'pdfs/revista', 'error'
    );

    expect($status)->toBe(201);
    // Mayúsculas intactas (convención productiva), NO lowercased ni con -1.
    expect($payload['data']['path'])->toBe('assets/pdfs/revista/2025-Revista-Digital-DICIEMBRE-2025.pdf');
});

test('finalize on_conflict=error devuelve 409 si el archivo ya existe (no reemplaza)', function () {
    sendChunk($this->controller, $this->uploadId, 0, $this->pdf);
    [$s1] = finalizeUpload($this->controller, $this->uploadId, 1, 'edicion-fija', 'pdfs/revista', 'error');
    expect($s1)->toBe(201);

    $id2 = bin2hex(random_bytes(16));
    sendChunk($this->controller, $id2, 0, $this->pdf);
    [$s2, $p2] = finalizeUpload($this->controller, $id2, 1, 'edicion-fija', 'pdfs/revista', 'error');

    expect($s2)->toBe(409);
    // El archivo original sigue siendo uno solo (no se creó -1 ni se reemplazó).
    expect(glob($this->tempDir . '/public/assets/pdfs/revista/*.pdf'))->toHaveCount(1);
});

test('finalize increment (default) añade -1 ante colisión', function () {
    sendChunk($this->controller, $this->uploadId, 0, $this->pdf);
    [$s1, $p1] = finalizeUpload($this->controller, $this->uploadId, 1, 'revista', 'uploads', 'increment');

    $id2 = bin2hex(random_bytes(16));
    sendChunk($this->controller, $id2, 0, $this->pdf);
    [$s2, $p2] = finalizeUpload($this->controller, $id2, 1, 'revista', 'uploads', 'increment');

    expect($s1)->toBe(201);
    expect($s2)->toBe(201);
    expect($p2['data']['path'])->not->toBe($p1['data']['path']); // revista.pdf vs revista-1.pdf
});

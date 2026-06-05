<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Rkn\Cms\Http\Controllers\CommandApiController;

/** Construye un POST multipart con el .xml en el campo 'file' + el body de opciones. */
function wxrUploadRequest(string $xml, array $body): ServerRequest
{
    $upload = new UploadedFile(Stream::create($xml), strlen($xml), UPLOAD_ERR_OK, 'export.xml', 'text/xml');

    return (new ServerRequest('POST', '/api/v1/commands/wxr-import'))
        ->withUploadedFiles(['file' => $upload])
        ->withParsedBody($body);
}

/** WXR mínimo válido con un post publicado. */
function minimalWxr(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
    <channel>
      <wp:wxr_version>1.2</wp:wxr_version>
      <item>
        <title>Hola Mundo</title>
        <link>http://example.com/hola-mundo</link>
        <dc:creator>admin</dc:creator>
        <content:encoded><![CDATA[<p>Contenido de prueba.</p>]]></content:encoded>
        <wp:post_id>1</wp:post_id>
        <wp:post_date>2024-01-15 10:00:00</wp:post_date>
        <wp:post_name>hola-mundo</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_type>post</wp:post_type>
      </item>
    </channel>
    </rss>
    XML;
}

/**
 * El endpoint de comandos ejecuta SOLO una allowlist de comandos de mantenimiento
 * (cache/índice/sitemap/cola), sin argumentos del usuario. Cualquier otro comando
 * (serve, deploy, wxr:import, init, boost…) se rechaza con 404 antes de ejecutarse.
 */

test('list returns the maintenance command allowlist', function () {
    $response = (new CommandApiController(sys_get_temp_dir()))->list();
    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($data['commands'])->toContain('cache:clear');
    expect($data['commands'])->toContain('cache:warmup');
    expect($data['commands'])->toContain('index:rebuild');
    expect($data['commands'])->toContain('queue:process');
    expect($data['commands'])->not->toContain('serve');
    expect($data['commands'])->not->toContain('deploy');
});

test('run rejects a command outside the allowlist with 404', function () {
    $response = (new CommandApiController(sys_get_temp_dir()))->run('serve');
    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(404);
    expect($data['error'])->toContain('serve');
    expect($data['allowed'])->toContain('cache:clear');
});

test('run rejects dangerous/destructive commands', function (string $cmd) {
    $response = (new CommandApiController(sys_get_temp_dir()))->run($cmd);

    expect($response->getStatusCode())->toBe(404);
})->with(['deploy', 'init', 'wxr:import', 'boost', 'mcp:serve', 'make:collection', 'llms:generate']);

test('run executes an allowlisted command and returns its output', function () {
    $dir = $this->makeTempDir();

    // cache:clear resuelve rutas vía getcwd(); el controller hace chdir($dir).
    // En un dir vacío reporta "Cache is already clean." con exit 0.
    $response = (new CommandApiController($dir))->run('cache:clear');
    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($data['ok'])->toBeTrue();
    expect($data['exit_code'])->toBe(0);
    expect($data['command'])->toBe('cache:clear');
    expect($data['output'])->toContain('clean');
});

test('run restores the working directory afterwards', function () {
    $before = getcwd();
    (new CommandApiController($this->makeTempDir()))->run('cache:clear');

    expect(getcwd())->toBe($before);
});

test('importWxr rejects a non-XML upload with 415', function () {
    $response = (new CommandApiController($this->makeTempDir()))
        ->importWxr(wxrUploadRequest('esto no es xml', ['collection' => 'blog']));

    expect($response->getStatusCode())->toBe(415);
});

test('importWxr rejects when no file was uploaded with 400', function () {
    $request = (new ServerRequest('POST', '/api/v1/commands/wxr-import'))->withParsedBody(['collection' => 'blog']);
    $response = (new CommandApiController($this->makeTempDir()))->importWxr($request);

    expect($response->getStatusCode())->toBe(400);
});

test('importWxr runs wxr:import and writes content from a valid WXR', function () {
    $dir = $this->makeTempDir();

    $response = (new CommandApiController($dir))
        ->importWxr(wxrUploadRequest(minimalWxr(), ['collection' => 'blog', 'post_type' => 'post']));
    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($data['command'])->toBe('wxr:import');
    expect($data['exit_code'])->toBe(0);
    expect($data['collection'])->toBe('blog');

    // Efecto real: se creó al menos un .md bajo content/blog/.
    $mdFiles = [];
    if (is_dir($dir . '/content/blog')) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir . '/content/blog', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'md') {
                $mdFiles[] = $entry->getPathname();
            }
        }
    }
    expect($mdFiles)->not->toBeEmpty();

    // El XML temporal se limpió tras importar.
    expect(glob($dir . '/storage/uploads/wxr/*.xml'))->toBe([]);
});

test('importWxr sanea el slug de colección (sin traversal)', function () {
    $dir = $this->makeTempDir();

    (new CommandApiController($dir))
        ->importWxr(wxrUploadRequest(minimalWxr(), ['collection' => '../../etc/blog', 'post_type' => 'post']));

    // El slug se sanea a 'etcblog' (solo [a-z0-9_-]); no escapa de content/.
    expect(is_dir($dir . '/content/etcblog'))->toBeTrue();
    expect(is_dir($dir . '/etc'))->toBeFalse();
});

<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Rkn\Cms\Deploy\ArtifactBuilder;
use ZipArchive;

/**
 * Singleton lazy bootstrap for the deploy.php.stub integration suite.
 *
 * Starts a single `php -S` instance for the whole test process and exposes
 * helpers used across DeployPhp/*Test.php files. Registering this once via
 * DeployStubServer::bootstrap() at the top of each test file keeps the cost
 * of `php -S` startup at exactly one regardless of how many split files use it.
 */
final class DeployStubServer
{
    private static ?int $port = null;
    private static ?string $secret = null;
    private static ?string $root = null;
    private static bool $shutdownRegistered = false;

    public static function bootstrap(): void
    {
        if (self::$port !== null) {
            return;
        }

        $sock = socket_create_listen(0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);

        self::$port   = (int) $port;
        self::$secret = 'integration-test-secret-xyz';
        self::$root   = sys_get_temp_dir() . '/rakun-stub-test-' . uniqid();

        $root = self::$root;
        mkdir("{$root}/releases", 0755, true);
        mkdir("{$root}/shared/logs", 0755, true);
        file_put_contents("{$root}/shared/.env", "DEPLOY_SECRET=" . self::$secret . "\n");

        $stubSrc = dirname(__DIR__, 2) . '/src/Cms/Deploy/Resources/deploy.php.stub';
        copy($stubSrc, "{$root}/deploy.php");

        $serverLog = "{$root}/server.log";
        $cmd = "herd php -S 127.0.0.1:{$port} {$root}/deploy.php >> {$serverLog} 2>&1 &";
        exec($cmd);

        $deadline = microtime(true) + 6.0;
        while (microtime(true) < $deadline) {
            $ping = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($ping !== false) {
                fclose($ping);
                break;
            }
            usleep(50_000);
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function(function () use ($port, $root): void {
                $killCmd = "lsof -ti tcp:{$port} 2>/dev/null | xargs kill -9 2>/dev/null || true";
                exec($killCmd);
                self::rmrf($root);
            });
            self::$shutdownRegistered = true;
        }
    }

    public static function port(): int
    {
        return self::$port ?? throw new \LogicException('Call bootstrap() first.');
    }

    public static function secret(): string
    {
        return self::$secret ?? throw new \LogicException('Call bootstrap() first.');
    }

    public static function root(): string
    {
        return self::$root ?? throw new \LogicException('Call bootstrap() first.');
    }

    public static function url(): string
    {
        return 'http://127.0.0.1:' . self::port() . '/deploy.php';
    }

    /**
     * Resolve the release ID of the currently-active deployment.
     *
     * Under the symlink-less architecture deploy.php.stub uses for cPanel/FTP
     * legacy hosting (`disable_functions` often bans symlink()), `current` is a
     * real DIRECTORY produced by rename(), not a symlink — so identity comes
     * from its manifest.json, not readlink(). Mirrors the stub's own tolerant
     * read of both key spellings (handleActivate/handleStatus).
     */
    public static function currentRelease(): ?string
    {
        $manifest = self::root() . '/current/manifest.json';
        if (!is_file($manifest)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($data)) {
            return null;
        }
        return $data['releaseId'] ?? $data['release_id'] ?? null;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    public static function hmacHeaders(string $body, ?string $secret = null): array
    {
        $secret = $secret ?? self::secret();
        $ts  = time();
        $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);
        return [
            "X-Rakun-Signature: {$sig}",
            "X-Rakun-Timestamp: {$ts}",
            'Content-Type: application/json',
        ];
    }

    /**
     * @param  string[] $headers
     * @return array{0:int,1:string}
     */
    public static function request(string $body, array $headers, ?string $url = null): array
    {
        $url = $url ?? self::url();
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = (string) curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, $response];
    }

    /**
     * @param array<string,string> $extraFiles
     */
    public static function buildTestZip(string $releaseId, array $extraFiles = []): void
    {
        $root   = self::root();
        $secret = self::secret();

        $src = sys_get_temp_dir() . '/rakun-stub-src-' . uniqid();
        mkdir($src, 0755, true);
        file_put_contents("{$src}/index.php", '<?php echo "release";');
        foreach ($extraFiles as $path => $content) {
            $dir = dirname("{$src}/{$path}");
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents("{$src}/{$path}", $content);
        }

        $builder  = new ArtifactBuilder($src);
        $zipPath  = $builder->build($releaseId, [], null, null, 'lean', $secret);
        $hmacPath = "{$zipPath}.hmac";

        rename($zipPath, "{$root}/releases/{$releaseId}.zip");
        rename($hmacPath, "{$root}/releases/{$releaseId}.zip.hmac");

        foreach (glob("{$src}/*") ?: [] as $f) {
            unlink($f);
        }
        rmdir($src);
    }

    public static function clearLock(): void
    {
        $lock = self::root() . '/shared/lock.json';
        if (file_exists($lock)) {
            unlink($lock);
        }
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) && !is_link($path) ? self::rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

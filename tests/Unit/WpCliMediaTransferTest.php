<?php

namespace Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\Concerns\WpCli\SupportsWpCliConnections;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use PHPUnit\Framework\TestCase;

class WpCliMediaTransferTest extends TestCase
{
    public function test_local_transport_stages_the_exact_media_bytes(): void
    {
        $directory = sys_get_temp_dir() . '/hexa-wpt-media-' . bin2hex(random_bytes(6));
        $source = $directory . '/source.png';
        $target = $directory . '/wordpress/.hexa-import/image.png';
        mkdir($directory, 0700, true);
        file_put_contents($source, "validated-media-bytes\0with-binary");

        try {
            $error = (new WpCliMediaTransferHarness())->transfer(
                new WhmServer(),
                new LocalShellConnection(),
                $source,
                $target,
            );

            $this->assertNull($error);
            $this->assertFileExists($target);
            $this->assertSame(file_get_contents($source), file_get_contents($target));
            $this->assertSame(filesize($source), filesize($target));
            $this->assertSame(0755, fileperms(dirname($target)) & 0777);
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
            if (is_file($source)) {
                unlink($source);
            }
            @rmdir(dirname($target));
            @rmdir(dirname(dirname($target)));
            @rmdir($directory);
        }
    }

    public function test_missing_source_returns_a_specific_transfer_error(): void
    {
        $error = (new WpCliMediaTransferHarness())->transfer(
            new WhmServer(),
            new LocalShellConnection(),
            '/tmp/hexa-media-source-does-not-exist',
            '/tmp/hexa-media-target-does-not-exist',
        );

        $this->assertSame('Local source file does not exist or is not readable.', $error);
    }

    public function test_media_import_uses_transport_aware_staging(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/Services/Concerns/WpCli/ManagesWpCliMedia.php',
        );

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'transferLocalFileToWpCliServer($server, $connection, $sourcePath, $tmpFile)',
            $source,
        );
        $this->assertStringNotContainsString(
            'cp -f " . escapeshellarg($sourcePath)',
            $source,
        );
    }
}

class WpCliMediaTransferHarness
{
    use SupportsWpCliConnections;

    public function transfer(
        WhmServer $server,
        LocalShellConnection $connection,
        string $sourcePath,
        string $targetPath,
    ): ?string {
        return $this->transferLocalFileToWpCliServer(
            $server,
            $connection,
            $sourcePath,
            $targetPath,
        );
    }
}

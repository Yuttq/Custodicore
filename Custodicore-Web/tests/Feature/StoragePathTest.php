<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

use function Illuminate\Filesystem\join_paths;

/**
 * On Azure App Service, uploads must live outside the deployed code:
 * LARAVEL_STORAGE_PATH (an App Setting, e.g. /home/custodicore/storage) moves
 * Laravel's whole storage folder, and the private `local` disk with it.
 * Laravel 12 reads the variable itself (Application::storagePath()); these
 * tests pin that behavior. No files are read or written.
 */
class StoragePathTest extends TestCase
{
    private const AZURE_PATH = '/home/custodicore/storage';

    /** @var array{env: ?string, server: ?string} */
    private array $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = [
            'env' => $_ENV['LARAVEL_STORAGE_PATH'] ?? null,
            'server' => $_SERVER['LARAVEL_STORAGE_PATH'] ?? null,
        ];
        unset($_ENV['LARAVEL_STORAGE_PATH'], $_SERVER['LARAVEL_STORAGE_PATH']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['LARAVEL_STORAGE_PATH'], $_SERVER['LARAVEL_STORAGE_PATH']);
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
            if ($this->original[$key] !== null) {
                $GLOBALS[$global]['LARAVEL_STORAGE_PATH'] = $this->original[$key];
            }
        }

        parent::tearDown();
    }

    /** config/filesystems.php as it would be loaded right now. */
    private function filesystemsConfig(): array
    {
        return require config_path('filesystems.php');
    }

    public function test_without_the_setting_storage_stays_in_the_project(): void
    {
        $this->assertSame(base_path('storage'), $this->app->storagePath());
        $this->assertSame(join_paths(base_path('storage'), 'app/private'), storage_path('app/private'));
        $this->assertSame(join_paths(base_path('storage'), 'app/private'), $this->filesystemsConfig()['disks']['local']['root']);
    }

    public function test_an_app_setting_moves_the_storage_folder(): void
    {
        // App Service passes App Settings to PHP as environment variables, which land in $_SERVER.
        $_SERVER['LARAVEL_STORAGE_PATH'] = self::AZURE_PATH;

        $this->assertSame(self::AZURE_PATH, $this->app->storagePath());
        $this->assertSame(join_paths(self::AZURE_PATH, 'logs'), storage_path('logs'));
        $this->assertSame(join_paths(self::AZURE_PATH, 'framework/views'), storage_path('framework/views'));
    }

    public function test_the_setting_is_also_read_from_env(): void
    {
        $_ENV['LARAVEL_STORAGE_PATH'] = self::AZURE_PATH;

        $this->assertSame(self::AZURE_PATH, $this->app->storagePath());
    }

    public function test_the_private_disk_follows_the_storage_folder(): void
    {
        $_SERVER['LARAVEL_STORAGE_PATH'] = self::AZURE_PATH;

        $disks = $this->filesystemsConfig()['disks'];

        $this->assertSame(join_paths(self::AZURE_PATH, 'app/private'), $disks['local']['root']);
        // The legacy public disk (old government IDs, read-only fallback) moves with it.
        $this->assertSame(join_paths(self::AZURE_PATH, 'app/public'), $disks['public']['root']);
    }

    public function test_the_signed_storage_routes_are_not_registered(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));

        $this->get('/storage/visitor-ids/anything.jpg')->assertNotFound();
        $this->put('/storage/visitor-ids/anything.jpg')->assertNotFound();
    }
}

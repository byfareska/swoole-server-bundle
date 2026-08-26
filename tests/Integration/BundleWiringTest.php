<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\Integration;

use Byfareska\SwooleServer\Command\ServerStartCommand;
use Byfareska\SwooleServer\Metrics\ServerMetrics;
use Byfareska\SwooleServer\SwooleServerBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Boots a real kernel with FrameworkBundle + SwooleServerBundle and compiles
 * the container — a smoke test for the whole wiring (services.php,
 * loadExtension's replaceArgument calls, autoconfiguration, tagged iterators).
 */
final class BundleWiringTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            $this->kernel = null;
            self::removeDirectory(\dirname($cacheDir));
        }

        // Kernel::boot() registers a global exception handler (ErrorHandler);
        // pop it so PHPUnit does not flag the test as risky.
        restore_exception_handler();
    }

    public function testContainerCompilesAndTheCommandIsWired(): void
    {
        $this->kernel = new WiringTestKernel('test', false);
        $this->kernel->boot();

        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        $command = $container->get(ServerStartCommand::class);
        self::assertInstanceOf(ServerStartCommand::class, $command);
        self::assertSame('byfareska:swoole:server:start', $command->getName());

        // metrics.enabled → the ServerMetrics service is wired (it is private,
        // so it is reachable only through the test container).
        self::assertInstanceOf(ServerMetrics::class, $container->get(ServerMetrics::class));
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

final class WiringTestKernel extends Kernel
{
    private ?string $varDir = null;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SwooleServerBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
            ]);
            $container->loadFromExtension('byfareska_swoole_server', [
                'dsn' => 'swoole://127.0.0.1:9501?workers=2',
                'health_check_path' => '/healthz',
                'metrics' => ['enabled' => true, 'path' => '/metrics', 'namespace' => 'test_app'],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return $this->varDir() . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDir() . '/log';
    }

    private function varDir(): string
    {
        // Unique per kernel instance: a stale compiled container from an earlier
        // run must not mask wiring regressions.
        return $this->varDir ??= sys_get_temp_dir() . '/byfareska_swoole_wiring_' . bin2hex(random_bytes(6));
    }
}

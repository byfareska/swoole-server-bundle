<?php

declare(strict_types=1);

use Byfareska\SwooleServer\Bridge\Body\BinaryFileBodyEmitter;
use Byfareska\SwooleServer\Bridge\Body\ChunkYieldingBodyEmitter;
use Byfareska\SwooleServer\Bridge\Body\ContentBodyEmitter;
use Byfareska\SwooleServer\Bridge\Body\StreamedBodyEmitter;
use Byfareska\SwooleServer\Bridge\SwooleRequestFactory;
use Byfareska\SwooleServer\Bridge\SwooleRequestFactoryInterface;
use Byfareska\SwooleServer\Bridge\SwooleResponseEmitter;
use Byfareska\SwooleServer\Command\ServerStartCommand;
use Byfareska\SwooleServer\ErrorHandler\ExceptionResponseFactory;
use Byfareska\SwooleServer\ErrorHandler\ExceptionResponseFactoryInterface;
use Byfareska\SwooleServer\HotReload\DirectoryFingerprint;
use Byfareska\SwooleServer\HotReload\HotReloadWatcher;
use Byfareska\SwooleServer\Metrics\ProcessMemoryReader;
use Byfareska\SwooleServer\Metrics\PrometheusTextRenderer;
use Byfareska\SwooleServer\Metrics\ServerMetrics;
use Byfareska\SwooleServer\Metrics\WorkerMetricsTable;
use Byfareska\SwooleServer\Metrics\WorkerSampler;
use Byfareska\SwooleServer\Profiler\ExceptionProfileCollector;
use Byfareska\SwooleServer\Reset\FormDataCollectorResetter;
use Byfareska\SwooleServer\Reset\SymfonyServicesResetter;
use Byfareska\SwooleServer\Reset\WorkerStateResetter;
use Byfareska\SwooleServer\Runtime\SwooleRequestHandler;
use Byfareska\SwooleServer\Runtime\WorkerKernelFactory;
use Byfareska\SwooleServer\Runtime\WorkerKernelFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Our own renderer instance instead of an alias to a framework-bundle
    // internal service — we do not depend on private service ids.
    $services->set('byfareska_swoole_server.error_renderer.html', HtmlErrorRenderer::class)
        ->args([param('kernel.debug')]);

    $services->set(SwooleRequestFactory::class);

    // Swap the Swoole→HttpFoundation request mapping (e.g. extra attributes)
    // by re-aliasing this interface to your own implementation.
    $services->alias(SwooleRequestFactoryInterface::class, SwooleRequestFactory::class);

    // Built-in body emission strategies. Third parties register their own by
    // implementing ResponseBodyEmitterInterface — autoconfiguration adds the
    // tag; the first strategy whose supports() returns true wins (priority
    // desc), with ContentBodyEmitter as the catch-all fallback.
    $services->set(BinaryFileBodyEmitter::class)
        ->tag('byfareska_swoole_server.response_body_emitter', ['priority' => 100]);

    // Above StreamedBodyEmitter: ChunkYieldingStreamedResponse extends StreamedResponse.
    $services->set(ChunkYieldingBodyEmitter::class)
        ->tag('byfareska_swoole_server.response_body_emitter', ['priority' => 50]);

    $services->set(StreamedBodyEmitter::class)
        ->args([service('logger')->nullOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'swoole_server'])
        ->tag('byfareska_swoole_server.response_body_emitter', ['priority' => 25]);

    $services->set(ContentBodyEmitter::class)
        ->tag('byfareska_swoole_server.response_body_emitter', ['priority' => -100]);

    $services->set(SwooleResponseEmitter::class)
        ->args([tagged_iterator('byfareska_swoole_server.response_body_emitter')]);

    $services->set(ExceptionResponseFactory::class)
        ->args([service('byfareska_swoole_server.error_renderer.html')]);

    // Swap the error response format (e.g. JSON for an API) by re-aliasing
    // this interface to your own implementation or decorating the default one.
    $services->alias(ExceptionResponseFactoryInterface::class, ExceptionResponseFactory::class);

    $services->set(ExceptionProfileCollector::class);

    // Built-in resetters. Third parties register their own by implementing
    // WorkerResetterInterface — autoconfiguration adds the tag for them.
    $services->set(SymfonyServicesResetter::class)
        ->tag('byfareska_swoole_server.worker_resetter', ['priority' => 100]);

    // Runs after SymfonyServicesResetter: first the collector's own reset()
    // does what it can, then we wipe the leftover buffers.
    $services->set(FormDataCollectorResetter::class)
        ->tag('byfareska_swoole_server.worker_resetter', ['priority' => -100]);

    $services->set(WorkerStateResetter::class)
        ->args([tagged_iterator('byfareska_swoole_server.worker_resetter')]);

    $services->set(DirectoryFingerprint::class);

    $services->set(HotReloadWatcher::class)
        ->args([
            service(DirectoryFingerprint::class),
            abstract_arg('watch dirs (config hot_reload.watch_dirs)'),
            abstract_arg('interval (config hot_reload.interval)'),
            abstract_arg('extensions (config hot_reload.extensions)'),
        ]);

    $services->set(WorkerKernelFactory::class)
        ->args([
            service('kernel'),
            abstract_arg('kernel class (config kernel_class)'),
        ]);

    // Swap the per-worker kernel boot (e.g. cache prewarming) by re-aliasing
    // this interface to your own implementation.
    $services->alias(WorkerKernelFactoryInterface::class, WorkerKernelFactory::class);

    $services->set(SwooleRequestHandler::class)
        ->args([
            service(SwooleRequestFactoryInterface::class),
            service(SwooleResponseEmitter::class),
            service(ExceptionResponseFactoryInterface::class),
            service(ExceptionProfileCollector::class),
            service(WorkerStateResetter::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'swoole_server']);

    // Server-level Prometheus metrics (config "metrics"). One shared table
    // instance: the sampler writes to it in every worker, ServerMetrics reads
    // it in the worker serving the scrape.
    $services->set(WorkerMetricsTable::class);

    $services->set(ProcessMemoryReader::class);

    $services->set(WorkerSampler::class)
        ->args([
            service(WorkerMetricsTable::class),
            service(ProcessMemoryReader::class),
            abstract_arg('sample interval (config metrics.sample_interval)'),
        ]);

    $services->set(PrometheusTextRenderer::class)
        ->args([abstract_arg('metric namespace (config metrics.namespace)')]);

    $services->set(ServerMetrics::class)
        ->args([
            service(WorkerMetricsTable::class),
            service(WorkerSampler::class),
            service(ProcessMemoryReader::class),
            service(PrometheusTextRenderer::class),
            abstract_arg('metrics path (config metrics.path)'),
        ]);

    $services->set(ServerStartCommand::class)
        ->args([
            service(WorkerKernelFactoryInterface::class),
            service(SwooleRequestHandler::class),
            service(HotReloadWatcher::class),
            abstract_arg('dsn (config dsn)'),
            abstract_arg('hot reload enabled (config hot_reload.enabled)'),
            abstract_arg('health check path (config health_check_path)'),
            param('kernel.debug'),
            abstract_arg('metrics (config metrics.enabled → ServerMetrics or null)'),
        ])
        ->tag('console.command');
};

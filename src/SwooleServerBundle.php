<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer;

use Byfareska\SwooleServer\Bridge\Body\ResponseBodyEmitterInterface;
use Byfareska\SwooleServer\Command\ServerStartCommand;
use Byfareska\SwooleServer\HotReload\HotReloadWatcher;
use Byfareska\SwooleServer\Reset\WorkerResetterInterface;
use Byfareska\SwooleServer\Runtime\WorkerKernelFactory;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SwooleServerBundle extends AbstractBundle
{
    // Config root / extension alias (the class name alone would derive "swoole_server").
    protected string $extensionAlias = 'byfareska_swoole_server';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('dsn')
                    ->cannotBeEmpty()
                    ->defaultValue('swoole://0.0.0.0:8000')
                    ->info(
                        'Server DSN: swoole://host:port?workers=4&package_max_length=67108864&log_level=info. '
                        . 'The "workers" parameter controls the number of workers (0 = swoole_cpu_num()), the '
                        . 'remaining query parameters go 1:1 to Swoole $server->set(). Usually: "%env(SWOOLE_SERVER_DSN)%".'
                    )
                ->end()
                ->scalarNode('kernel_class')
                    ->defaultNull()
                    ->info('FQCN of the kernel booted per worker; null = the application kernel class.')
                ->end()
                ->scalarNode('health_check_path')
                    ->defaultNull()
                    ->info(
                        'Path answered with a plain-text 200 "ok" before the kernel (e.g. "/healthz") — '
                        . 'reports liveness of the server process, responds even while workers are booting. '
                        . 'null = disabled.'
                    )
                ->end()
                ->arrayNode('hot_reload')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultNull()
                            ->info('null = enabled only when kernel.debug')
                        ->end()
                        ->arrayNode('watch_dirs')
                            ->scalarPrototype()->end()
                            ->defaultValue([
                                '%kernel.project_dir%/src',
                                '%kernel.project_dir%/config',
                                '%kernel.project_dir%/templates',
                            ])
                        ->end()
                        ->integerNode('interval')
                            ->defaultValue(1000)
                            ->info('Scan interval in ms')
                        ->end()
                        ->arrayNode('extensions')
                            ->scalarPrototype()->end()
                            ->defaultValue(['php', 'twig', 'yaml'])
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{
     *     dsn: string,
     *     kernel_class: ?string,
     *     health_check_path: ?string,
     *     hot_reload: array{enabled: ?bool, watch_dirs: list<string>, interval: int, extensions: list<string>},
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        // Implementing the interface is enough to register a custom resetter —
        // autoconfiguration tags it for the WorkerStateResetter composite.
        $builder->registerForAutoconfiguration(WorkerResetterInterface::class)
            ->addTag('byfareska_swoole_server.worker_resetter');

        // Same mechanism for body emission strategies: implement the interface
        // and the SwooleResponseEmitter chain picks the strategy up.
        $builder->registerForAutoconfiguration(ResponseBodyEmitterInterface::class)
            ->addTag('byfareska_swoole_server.response_body_emitter');

        $builder->getDefinition(WorkerKernelFactory::class)
            ->replaceArgument(1, $config['kernel_class']);

        $builder->getDefinition(HotReloadWatcher::class)
            ->replaceArgument(1, $config['hot_reload']['watch_dirs'])
            ->replaceArgument(2, $config['hot_reload']['interval'])
            ->replaceArgument(3, $config['hot_reload']['extensions']);

        $builder->getDefinition(ServerStartCommand::class)
            ->replaceArgument(3, $config['dsn'])
            ->replaceArgument(4, $config['hot_reload']['enabled'])
            ->replaceArgument(5, $config['health_check_path']);
    }
}

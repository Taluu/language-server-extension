<?php

namespace Phpactor\Extension\LanguageServer;

use Phly\EventDispatcher\EventDispatcher;
use Phpactor\Container\Container;
use Phpactor\Container\ContainerBuilder;
use Phpactor\Container\Extension;
use Phpactor\Extension\LanguageServer\Dispatcher\PhpactorDispatcherFactory;
use Phpactor\Extension\LanguageServer\EventDispatcher\LazyAggregateProvider;
use Phpactor\Extension\LanguageServer\Handler\DebugHandler;
use Phpactor\Extension\Logger\LoggingExtension;
use Phpactor\Extension\Console\ConsoleExtension;
use Phpactor\Extension\LanguageServer\Command\StartCommand;
use Phpactor\LanguageServer\Core\CodeAction\AggregateCodeActionProvider;
use Phpactor\LanguageServer\Core\Command\CommandDispatcher;
use Phpactor\LanguageServer\Core\Diagnostics\AggregateDiagnosticsProvider;
use Phpactor\LanguageServer\Core\Diagnostics\DiagnosticsEngine;
use Phpactor\LanguageServer\Diagnostics\CodeActionDiagnosticsProvider;
use Phpactor\LanguageServer\Handler\System\ExitHandler;
use Phpactor\LanguageServer\Handler\System\StatsHandler;
use Phpactor\LanguageServer\Handler\TextDocument\CodeActionHandler;
use Phpactor\LanguageServer\Handler\TextDocument\TextDocumentHandler;
use Phpactor\LanguageServer\Core\Handler\HandlerMethodRunner;
use Phpactor\LanguageServer\Adapter\DTL\DTLArgumentResolver;
use Phpactor\LanguageServer\Core\Dispatcher\ArgumentResolver\LanguageSeverProtocolParamsResolver;
use Phpactor\LanguageServer\Core\Dispatcher\ArgumentResolver\ChainArgumentResolver;
use Phpactor\LanguageServer\Core\Dispatcher\ArgumentResolver;
use Phpactor\LanguageServer\Middleware\HandlerMiddleware;
use Phpactor\LanguageServer\Core\Server\ResponseWatcher;
use Phpactor\LanguageServer\Middleware\ResponseHandlingMiddleware;
use Phpactor\LanguageServer\Middleware\MethodAliasMiddleware;
use Phpactor\LanguageServer\Core\Handler\MethodRunner;
use Phpactor\LanguageServer\Middleware\CancellationMiddleware;
use Phpactor\LanguageServer\Core\Handler\Handlers;
use Phpactor\LanguageServer\Middleware\InitializeMiddleware;
use Phpactor\LanguageServer\Middleware\ErrorHandlingMiddleware;
use Phpactor\LanguageServer\Core\Dispatcher\Dispatcher\MiddlewareDispatcher;
use Phpactor\LanguageServer\Core\Service\ServiceProvider;
use Phpactor\LanguageServer\Core\Service\ServiceProviders;
use Phpactor\LanguageServer\Listener\ServiceListener;
use Phpactor\LanguageServer\Handler\Workspace\CommandHandler;
use Phpactor\LanguageServer\Core\Service\ServiceManager;
use Phpactor\LanguageServer\Handler\System\ServiceHandler;
use Phpactor\LanguageServer\Core\Server\ClientApi;
use Phpactor\LanguageServer\Listener\WorkspaceListener;
use Phpactor\LanguageServer\Core\Workspace\Workspace;
use Phpactor\LanguageServer\LanguageServerBuilder;
use Phpactor\LanguageServer\Core\Server\ServerStats;
use Phpactor\LanguageServer\Service\DiagnosticsService;
use Phpactor\MapResolver\Resolver;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

class LanguageServerExtension implements Extension
{
    public const SERVICE_LANGUAGE_SERVER_BUILDER = 'language_server.builder';
    public const SERVICE_EVENT_EMITTER = 'language_server.event_emitter';
    public const SERVICE_SESSION_WORKSPACE = 'language_server.session.workspace';
    public const TAG_METHOD_HANDLER = 'language_server.session_handler';
    public const TAG_COMMAND = 'language_server.command';
    public const TAG_SERVICE_PROVIDER = 'language_server.service_provider';
    public const TAG_LISTENER_PROVIDER = 'language_server.listener_provider';
    public const TAG_CODE_ACTION_PROVIDER = 'language_server.code_action_provider';
    public const TAG_CODE_ACTION_DIAGNOSTICS_PROVIDER = 'language_server.code_action_diagnostics_provider';
    public const TAG_DIAGNOSTICS_PROVIDER = 'language_server.diagnostics_provider';

    public const PARAM_SESSION_PARAMETERS = 'language_server.session_parameters';
    public const PARAM_CLIENT_CAPABILITIES = 'language_server.client_capabilities';
    public const PARAM_ENABLE_WORKPACE = 'language_server.enable_workspace';
    public const PARAM_CATCH_ERRORS = 'language_server.catch_errors';
    public const PARAM_METHOD_ALIAS_MAP = 'language_server.method_alias_map';
    public const PARAM_DIAGNOSTIC_SLEEP_TIME = 'language_server.diagnostic_sleep_time';
    public const PARAM_DIAGNOSTIC_ON_UPDATE = 'language_server.diagnostics_on_update';
    public const PARAM_DIAGNOSTIC_ON_SAVE = 'language_server.diagnostics_on_save';

    /**
     * {@inheritDoc}
     */
    public function configure(Resolver $schema)
    {
        $schema->setDefaults([
            self::PARAM_CATCH_ERRORS => true,
            self::PARAM_ENABLE_WORKPACE => true,
            self::PARAM_SESSION_PARAMETERS => [],
            self::PARAM_METHOD_ALIAS_MAP => [],
            self::PARAM_DIAGNOSTIC_SLEEP_TIME => 1000,
            self::PARAM_DIAGNOSTIC_ON_UPDATE => false,
            self::PARAM_DIAGNOSTIC_ON_SAVE => true,
        ]);
        $schema->setDescriptions([
            self::PARAM_METHOD_ALIAS_MAP => 'Allow method names to be re-mapped. Useful for maintaining backwards compatibility',
            self::PARAM_SESSION_PARAMETERS => 'Phpactor parameters (config) that apply only to the language server session',
            self::PARAM_ENABLE_WORKPACE => <<<'EOT'
If workspace management / text synchronization should be enabled (this isn't required for some language server implementations, e.g. static analyzers)
EOT
            ,
            self::PARAM_DIAGNOSTIC_SLEEP_TIME => 'Amount of time to wait before analyzing the code again for diagnostics',
            self::PARAM_DIAGNOSTIC_ON_UPDATE => 'Perform diagnostics when the text document is updated',
            self::PARAM_DIAGNOSTIC_ON_SAVE => 'Perform diagnostics when the text document is saved',
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function load(ContainerBuilder $container)
    {
        $this->registerServer($container);
        $this->registerCommand($container);
        $this->registerSession($container);
        $this->registerEventDispatcher($container);
        $this->registerCommandDispatcher($container);
        $this->registerServiceManager($container);
        $this->registerMiddleware($container);
        $this->registerDiagnostics($container);
        $this->registerHandlers($container);
        $this->registerServices($container);
    }

    private function registerServer(ContainerBuilder $container): void
    {
        $container->register(ServerStats::class, function (Container $container) {
            return new ServerStats();
        });

        $container->register(LanguageServerBuilder::class, function (Container $container) {
            $builder = LanguageServerBuilder::create(
                new PhpactorDispatcherFactory($container),
                $container->get(LoggingExtension::SERVICE_LOGGER)
            );

            return $builder;
        });
    }

    private function registerCommand(ContainerBuilder $container): void
    {
        if (!class_exists(ConsoleExtension::class)) {
            return;
        }

        $container->register('language_server.command.lsp_start', function (Container $container) {
            return new StartCommand($container->get(LanguageServerBuilder::class));
        }, [ ConsoleExtension::TAG_COMMAND => [ 'name' => StartCommand::NAME ]]);
    }

    private function registerSession(ContainerBuilder $container): void
    {
        $container->register(self::SERVICE_SESSION_WORKSPACE, function (Container $container) {
            return new Workspace(
                $container->get(LoggingExtension::SERVICE_LOGGER)
            );
        });

        $container->register(WorkspaceListener::class, function (Container $container) {
            if ($container->getParameter(self::PARAM_ENABLE_WORKPACE) === false) {
                return null;
            }

            return new WorkspaceListener($container->get(self::SERVICE_SESSION_WORKSPACE));
        }, [
            self::TAG_LISTENER_PROVIDER => [],
        ]);

        $container->register('language_server.session.handler.session', function (Container $container) {
            return new DebugHandler(
                $container,
                $container->get(ClientApi::class),
                $container->get(self::SERVICE_SESSION_WORKSPACE),
            );
        }, [ self::TAG_METHOD_HANDLER => []]);

        $container->register(ServiceHandler::class, function (Container $container) {
            return new ServiceHandler($container->get(ServiceManager::class), $container->get(ClientApi::class));
        }, [ self::TAG_METHOD_HANDLER => []]);

        $container->register(CommandHandler::class, function (Container $container) {
            return new CommandHandler($container->get(CommandDispatcher::class));
        }, [ self::TAG_METHOD_HANDLER => []]);
    }

    private function registerEventDispatcher(ContainerBuilder $container): void
    {
        $container->register(EventDispatcherInterface::class, function (Container $container) {
            $aggregate = new LazyAggregateProvider(
                $container,
                array_keys($container->getServiceIdsForTag(self::TAG_LISTENER_PROVIDER))
            );

            return new EventDispatcher($aggregate);
        });
    }

    private function registerCommandDispatcher(ContainerBuilder $container): void
    {
        $container->register(CommandDispatcher::class, function (Container $container) {
            $map = [];
            foreach ($container->getServiceIdsForTag(self::TAG_COMMAND) as $serviceId => $attrs) {
                if (!isset($attrs['name'])) {
                    throw new RuntimeException(sprintf(
                        'Cannot register command with service ID "%s" Each command must define a "name" attribute',
                        $serviceId
                    ));
                }
                assert(is_string($attrs['name']));
                $map[$attrs['name']] = $container->get($serviceId);
            }

            return new CommandDispatcher($map);
        });
    }

    private function registerServiceManager(ContainerBuilder $container): void
    {
        $container->register(ServiceListener::class, function (Container $container) {
            return new ServiceListener($container->get(ServiceManager::class));
        }, [
            self::TAG_LISTENER_PROVIDER => [],
        ]);

        $container->register(ServiceManager::class, function (Container $container) {
            return new ServiceManager(
                $container->get(ServiceProviders::class),
                $container->get(LoggingExtension::SERVICE_LOGGER)
            );
        });
        $container->register(ServiceProviders::class, function (Container $container) {
            $providers = [];
            foreach ($container->getServiceIdsForTag(self::TAG_SERVICE_PROVIDER) as $serviceId => $attrs) {
                $provider = $container->get($serviceId);
                if (!$provider instanceof ServiceProvider) {
                    throw new RuntimeException(sprintf(
                        'Tagged service provider "%s" does not implement ServiceProvider interface, is a "%s"',
                        $serviceId,
                        is_object($provider) ? get_class($provider) : gettype($provider)
                    ));
                }
                $providers[] = $provider;
            }

            return new ServiceProviders(...$providers);
        });
    }

    private function registerMiddleware(ContainerBuilder $container): void
    {
        $container->register(MiddlewareDispatcher::class, function (Container $container) {
            $stack = [];

            if ($container->getParameter(self::PARAM_CATCH_ERRORS)) {
                $stack[] = new ErrorHandlingMiddleware($container->get(LoggingExtension::SERVICE_LOGGER));
            }

            $stack[] = new InitializeMiddleware(
                $container->get(Handlers::class),
                $container->get(EventDispatcherInterface::class)
            );

            $stack[] = new CancellationMiddleware(
                $container->get(MethodRunner::class)
            );

            $stack[] = new MethodAliasMiddleware($container->getParameter(self::PARAM_METHOD_ALIAS_MAP));
            $stack[] = new ResponseHandlingMiddleware($container->get(ResponseWatcher::class));

            $stack[] = new HandlerMiddleware(
                $container->get(MethodRunner::class)
            );

            return new MiddlewareDispatcher(...$stack);
        });
    }

    private function registerHandlers(ContainerBuilder $container): void
    {
        $container->register(ArgumentResolver::class, function (Container $container) {
            return new ChainArgumentResolver(
                new LanguageSeverProtocolParamsResolver(),
                new DTLArgumentResolver(),
            );
        });
        $container->register(MethodRunner::class, function (Container $container) {
            return new HandlerMethodRunner(
                $container->get(Handlers::class),
                $container->get(ArgumentResolver::class),
                $container->get(LoggingExtension::SERVICE_LOGGER)
            );
        });

        $container->register(Handlers::class, function (Container $container) {
            $handlers = [];
        
            foreach (array_keys(
                $container->getServiceIdsForTag(LanguageServerExtension::TAG_METHOD_HANDLER)
            ) as $serviceId) {
                $handlers[] = $container->get($serviceId);
            }
        
            return new Handlers(...$handlers);
        });

        $container->register(TextDocumentHandler::class, function (Container $container) {
            return new TextDocumentHandler($container->get(EventDispatcherInterface::class));
        }, [ self::TAG_METHOD_HANDLER => []]);

        $container->register(StatsHandler::class, function (Container $container) {
            return new StatsHandler(
                $container->get(ClientApi::class),
                $container->get(ServerStats::class)
            );
        }, [ self::TAG_METHOD_HANDLER => []]);

        $container->register(ExitHandler::class, function (Container $container) {
            return new ExitHandler();
        }, [ self::TAG_METHOD_HANDLER => []]);

        $container->register(CodeActionHandler::class, function (Container $container) {
            return new CodeActionHandler(
                new AggregateCodeActionProvider(...$this->taggedServices($container, self::TAG_CODE_ACTION_PROVIDER)),
                $container->get(self::SERVICE_SESSION_WORKSPACE)
            );
        }, [ self::TAG_METHOD_HANDLER => []]);
    }

    private function registerServices(ContainerBuilder $container): void
    {
        $container->register(DiagnosticsService::class, function (Container $container) {
            return new DiagnosticsService(
                $container->get(DiagnosticsEngine::class),
                $container->getParameter(self::PARAM_DIAGNOSTIC_ON_UPDATE),
                $container->getParameter(self::PARAM_DIAGNOSTIC_ON_SAVE),
                $container->get(self::SERVICE_SESSION_WORKSPACE)
            );
        }, [
            self::TAG_SERVICE_PROVIDER => [],
            self::TAG_LISTENER_PROVIDER => [],
        ]);
    }

    private function registerDiagnostics(ContainerBuilder $container): void
    {
        $container->register(DiagnosticsEngine::class, function (Container $container) {
            $providers = [];
            foreach (array_keys($container->getServiceIdsForTag(self::TAG_DIAGNOSTICS_PROVIDER)) as $serviceId) {
                $providers[] = $container->get($serviceId);
            }

            return new DiagnosticsEngine(
                $container->get(ClientApi::class),
                new AggregateDiagnosticsProvider(
                    $container->get(LoggingExtension::SERVICE_LOGGER),
                    ...$providers
                ),
                $container->getParameter(self::PARAM_DIAGNOSTIC_SLEEP_TIME)
            );
        });

        $container->register(CodeActionDiagnosticsProvider::class, function (Container $container) {
            return new CodeActionDiagnosticsProvider(
                ...$this->taggedServices($container, self::TAG_CODE_ACTION_DIAGNOSTICS_PROVIDER)
            );
        }, [
            self::TAG_DIAGNOSTICS_PROVIDER => [],
        ]);
    }

    private function taggedServices(Container $container, string $tag): array
    {
        $providers = [];
        foreach (array_keys($container->getServiceIdsForTag($tag)) as $serviceId) {
            $providers[] = $container->get($serviceId);
        }
        return $providers;
    }
}

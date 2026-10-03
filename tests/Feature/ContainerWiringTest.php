<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\CustomSkills\Http\CustomSkillController;
use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Services\CustomSkillQuery;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriter;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface;
use Spora\Plugins\CustomSkills\Services\SkillAllowlistReader;
use Spora\Plugins\CustomSkills\Tools\ManageSkillTool;
use Spora\Services\ToolConfigService;
use Spora\Skills\SkillProviderRegistry;

/**
 * The container can actually build this plugin's graph — the one property the
 * hand-building suites cannot assert. A hard cycle through `ToolConfigService → … →
 * SkillProviderRegistry → CustomSkillProvider → CustomSkillQuery →
 * ToolConfigServiceInterface` made `bin/spora` fatal on boot.
 */
function buildHostContainer(): DI\Container
{
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    // The chain core wires in `OrchestratorContainerBindings`, with the provider
    // registered from the plugin's own `skillProviders()` list as `PluginLoader` does.
    $provider = new CustomSkillProvider(new CustomSkillQuery(skillComposer(), new Spora\Skills\SkillValidator()));

    $builder->addDefinitions([
        SkillProviderRegistry::class => \DI\factory(static fn(): SkillProviderRegistry
            => new SkillProviderRegistry([$provider])),
        Spora\Services\ToolConfigServiceInterface::class => \DI\autowire(ToolConfigService::class),
        // Host bindings the Kernel supplies. The plugin must not rebind them.
        Spora\Core\SecurityManagerInterface::class => \DI\factory(static fn()
            => new Spora\Core\SecurityManager(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))),
        Psr\Log\LoggerInterface::class => \DI\factory(static fn(): Psr\Log\LoggerInterface
            => new Monolog\Logger('test')),
        // AuthService needs the booted connection, so the controller needs `beforeEach`'s schema.
        Spora\Auth\AuthService::class => \DI\factory(static fn(): Spora\Auth\AuthService
            => new Spora\Auth\AuthService(new Delight\Auth\Auth(
                Illuminate\Database\Capsule\Manager::connection()->getPdo(),
                null,
                null,
                false,
            ))),
    ]);

    // Registered through the event the host dispatches, not a list that could drift from it.
    (new Spora\Plugins\CustomSkills\CustomSkillsPlugin())->onContainerBuilding(
        new ContainerBuildingEvent($builder),
    );

    return $builder->build();
}

it('resolves the plugin graph through a real DI container', function (): void {
    $container = buildHostContainer();

    expect($container->get(CustomSkillQueryInterface::class))->toBeInstanceOf(CustomSkillQuery::class)
        ->and($container->get(CustomSkillWriterInterface::class))->toBeInstanceOf(CustomSkillWriter::class)
        ->and($container->get(SkillAllowlistReader::class))->toBeInstanceOf(SkillAllowlistReader::class)
        ->and($container->get(CustomSkillController::class))->toBeInstanceOf(CustomSkillController::class)
        ->and($container->get(ManageSkillTool::class))->toBeInstanceOf(ManageSkillTool::class);
});

it('does not put ToolConfigService inside the provider dependency chain', function (): void {
    // The cycle guard, stated positively: the provider must build without ever
    // asking for the settings service. `SkillProviderRegistry` sits inside core's
    // own `ToolConfigService` chain, so anything it needs becomes one transitively.
    $container = buildHostContainer();

    $provider = new CustomSkillProvider($container->get(CustomSkillQueryInterface::class));
    $reflector = new ReflectionClass($provider);

    expect($reflector->getConstructor()?->getNumberOfRequiredParameters())->toBe(1);

    // And the reader that legitimately needs it resolves on its own.
    expect($container->get(SkillAllowlistReader::class))->toBeInstanceOf(SkillAllowlistReader::class);
});

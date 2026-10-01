<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills;

use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\CustomSkills\Http\CustomSkillController;
use Spora\Plugins\CustomSkills\Http\CustomSkillResource;
use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Services\AllowedSkillsScrubber;
use Spora\Plugins\CustomSkills\Services\AllowedSkillsScrubberInterface;
use Spora\Plugins\CustomSkills\Services\CustomSkillQuery;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriter;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface;
use Spora\Plugins\CustomSkills\Services\SkillAllowlistReader;
use Spora\Plugins\CustomSkills\Services\SkillComposer;
use Spora\Plugins\CustomSkills\Tools\ManageSkillTool;
use Spora\Plugins\Exceptions\PluginLoadFailedException;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point: the admin app, the `manage_skill` write tool (core's `skill` serves
 * the reads), a {@see SkillProviderInterface}, 9 REST routes, the migrations, the bindings.
 *
 * `skillPaths()` is deliberately not overridden: these skills live in the database and
 * the scan roots have no principal dimension.
 */
final class CustomSkillsPlugin extends AbstractPlugin implements EventSubscriberInterface
{
    private const AUTH = [AuthMiddleware::class, CsrfMiddleware::class];

    public function __construct()
    {
        // Fail loudly on a core that predates the provider seam. Without it an old core
        // would load the CRUD routes and the admin panel while no agent could see a
        // skill. `PluginLoader::boot()` has no per-plugin tolerance, so this throw is
        // caught by `Kernel` and skips plugin boot entirely — the whole plugin layer goes
        // down, not just this one. That is the trade the message states.
        if (!interface_exists(SkillProviderInterface::class)) {
            throw new PluginLoadFailedException(
                'The custom-skills plugin requires a spora-core that ships '
                . 'Spora\\Skills\\SkillProviderInterface. Either upgrade the host or disable the plugin. '
                . 'Note that PluginLoader::boot() has no per-plugin tolerance, so this exception disables '
                . 'every installed plugin for the boot, not only this one.',
            );
        }
    }

    public function getName(): string
    {
        return (new CustomSkillsApp())->displayName();
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    /**
     * Bind the plugin's interfaces and controllers; core's own services are re-listed so
     * the plugin resolves standalone in tests that skip the host boot path.
     *
     * `SkillProviderRegistry` is deliberately not bound: core owns it, and redefining it
     * would drop `FilesystemSkillProvider` and empty the `allowed_skills` picker.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $event->builder()->addDefinitions([
            CustomSkillQueryInterface::class   => \DI\autowire(CustomSkillQuery::class),
            CustomSkillWriterInterface::class  => \DI\autowire(CustomSkillWriter::class),
            SkillAllowlistReader::class        => \DI\autowire(),
            CustomSkillResource::class         => \DI\autowire(),
            CustomSkillController::class        => \DI\autowire(),
            ManageSkillTool::class             => \DI\autowire(),
            SkillComposer::class               => \DI\autowire(),
            SkillValidator::class              => \DI\autowire(),
            AllowedSkillsScrubberInterface::class => \DI\autowire(AllowedSkillsScrubber::class),
            PrincipalService::class            => \DI\autowire(),
            PrincipalResolver::class           => \DI\autowire(),
            ToolConfigServiceInterface::class  => \DI\autowire(\Spora\Services\ToolConfigService::class),
        ]);
    }

    /**
     * The 9 `/api/v1/custom-skills*` routes, behind Auth + CSRF. Each resolves its own
     * principal from `?principal_id=`, which replaces a separate group-principal route set.
     */
    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        $routes = $event->routes();
        $base = '/api/v1/custom-skills';

        $routes->addRoute('GET', $base, [CustomSkillController::class, 'index'], self::AUTH);
        $routes->addRoute('POST', $base, [CustomSkillController::class, 'store'], self::AUTH);
        $routes->addRoute('GET', $base . '/{name}', [CustomSkillController::class, 'show'], self::AUTH);
        $routes->addRoute('PUT', $base . '/{name}', [CustomSkillController::class, 'update'], self::AUTH);
        $routes->addRoute('DELETE', $base . '/{name}', [CustomSkillController::class, 'destroy'], self::AUTH);
        $routes->addRoute('GET', $base . '/{name}/files', [CustomSkillController::class, 'files'], self::AUTH);
        $routes->addRoute('GET', $base . '/{name}/files/{path}', [CustomSkillController::class, 'file'], self::AUTH);
        $routes->addRoute('GET', $base . '/{name}/allowlist', [CustomSkillController::class, 'allowlist'], self::AUTH);
        $routes->addRoute('POST', $base . '/{name}/restore', [CustomSkillController::class, 'restore'], self::AUTH);
    }

    /**
     * @return list<class-string<SkillProviderInterface>>
     */
    public function skillProviders(): array
    {
        return [CustomSkillProvider::class];
    }

    /**
     * @return list<class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array
    {
        return [ManageSkillTool::class];
    }

    /**
     * @return list<class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array
    {
        return [CustomSkillsApp::class];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/../database/migrations';
    }
}

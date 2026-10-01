<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use FastRoute\DataGenerator;
use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteParser\Std;
use Psr\Log\LoggerInterface;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\Exceptions\SchemaInstallFailedException;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\CustomSkills\CustomSkillsApp;
use Spora\Plugins\CustomSkills\CustomSkillsPlugin;
use Spora\Plugins\CustomSkills\Http\CustomSkillController;
use Spora\Plugins\CustomSkills\Http\CustomSkillResource;
use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Services\AllowedSkillsScrubber;
use Spora\Plugins\CustomSkills\Services\AllowedSkillsScrubberInterface;
use Spora\Plugins\CustomSkills\Services\CustomSkillQuery;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriter;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface;
use Spora\Plugins\CustomSkills\Services\SkillComposer;
use Spora\Plugins\CustomSkills\Tools\ManageSkillTool;
use Spora\Plugins\Exceptions\PluginLoadFailedException;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The plugin's contribution to the host: manifest, migrations, DI bindings,
 * routes, and the load-time guard. Constructed and driven by hand, so no
 * assertion here can be satisfied by a registration that only works in a boot.
 */
/**
 * Runtime check that a contributed class still satisfies the contract its
 * consumer type-hints against. `$class` is a `string` on purpose: a literal
 * class name makes PHPStan prove the assertion tautological, defeating the point.
 */
function implementsContract(string $class, string $contract): bool
{
    return is_subclass_of($class, $contract);
}

function pluginManifest(): array
{
    static $manifest = null;

    return $manifest ??= json_decode(
        (string) file_get_contents(BASE_PATH . '/plugin.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/**
 * Keeps the last parsed pattern: the generated dispatch regexes cannot be read back as routes.
 */
final class PluginRoutePatternParser extends Std
{
    public string $current = '';

    public function parse($route)
    {
        $this->current = $route;

        return parent::parse($route);
    }
}

/**
 * Records `(method, route, handler)` into a real generator, so the host collector stays real.
 */
final class PluginRouteRecorder implements DataGenerator
{
    /** @var list<array{method: string, route: string, handler: mixed}> */
    public array $recorded = [];

    public function __construct(
        private readonly PluginRoutePatternParser $parser,
        private readonly DataGenerator $inner,
    ) {}

    public function addRoute($httpMethod, $routeData, $handler)
    {
        $this->recorded[] = [
            'method'  => $httpMethod,
            'route'   => $this->parser->current,
            'handler' => $handler,
        ];

        $this->inner->addRoute($httpMethod, $routeData, $handler);
    }

    public function getData()
    {
        return $this->inner->getData();
    }
}

/**
 * The registrations on a real host collector, plus the collector so it is provably dispatchable.
 *
 * @return array{0: list<array{method: string, route: string, handler: mixed}>, 1: PluginRouteRecorder}
 */
function pluginRouteRegistrations(CustomSkillsPlugin $plugin): array
{
    $parser = new PluginRoutePatternParser();
    $recorder = new PluginRouteRecorder($parser, new GroupCountBased());

    $plugin->onRoutesRegistering(new RoutesRegisteringEvent(
        new MiddlewareRouteCollector($parser, $recorder),
    ));

    return [$recorder->recorded, $recorder];
}

/**
 * The raw definition map, read from the private `definitionSources`: a built
 * container no longer distinguishes "bound to X" from "autowired by reflection".
 *
 * @return array<string, DI\Definition\Helper\CreateDefinitionHelper>
 */
function pluginContainerDefinitions(CustomSkillsPlugin $plugin): array
{
    $builder = new ContainerBuilder();
    $plugin->onContainerBuilding(new ContainerBuildingEvent($builder));

    $sources = (new ReflectionProperty(ContainerBuilder::class, 'definitionSources'))->getValue($builder);

    $definitions = [];
    foreach ($sources as $source) {
        if (is_array($source)) {
            $definitions = array_merge($definitions, $source);
        }
    }

    return $definitions;
}

function pluginDefinitionTarget(DI\Definition\Helper\CreateDefinitionHelper $helper, string $entryId): string
{
    return $helper->getDefinition($entryId)->getClassName();
}

/**
 * A throwaway migrations dir; explicit filenames keep negative cases readable in failure output.
 *
 * @param list<string> $filenames
 */
function pluginTempMigrationsDir(array $filenames): string
{
    $dir = sys_get_temp_dir() . '/custom_skills_migrations_' . bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    foreach ($filenames as $filename) {
        file_put_contents($dir . '/' . $filename, "<?php\n\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {};\n");
    }

    return $dir;
}

function pluginRemoveTempDir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

function pluginCallValidateMigrationFilenames(string $slug, string $path): void
{
    $installer = new DatabaseSchemaInstaller(null, null, $path);

    (new ReflectionMethod(DatabaseSchemaInstaller::class, 'validateMigrationFilenames'))
        ->invoke($installer, $slug, $path);
}

it('declares the custom-skills slug and a class that really exists', function (): void {
    $manifest = pluginManifest();

    expect($manifest['slug'])->toBe('custom-skills')
        ->and($manifest['class'])->toBe(CustomSkillsPlugin::class)
        ->and(class_exists($manifest['class']))->toBeTrue()
        ->and(is_subclass_of($manifest['class'], Spora\Plugins\AbstractPlugin::class))->toBeTrue()
        ->and((new ReflectionClass($manifest['class']))->getNamespaceName())
        ->toBe('Spora\Plugins\CustomSkills');
});

/**
 * D16. The prefix is the manifest slug verbatim, hyphen included — asserted
 * against both a literal and a `plugin.json`-derived slug, so a hyphenless
 * rename fails here rather than at install time on a host the author cannot see.
 */
it('prefixes every migration with the manifest slug verbatim, hyphen included', function (): void {
    $slug = pluginManifest()['slug'];
    $path = (new CustomSkillsPlugin())->migrationsPath();

    $files = glob($path . '/*.php') ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $basename = basename($file, '.php');

        expect($basename)->toStartWith('custom-skills_')
            ->and($basename)->toStartWith($slug . '_');
    }

    // A hyphenless prefix must not be accepted — that is why the hyphen matters.
    expect(basename($files[0], '.php'))->not->toStartWith(str_replace('-', '', $slug) . '_');
});

it('passes the host\'s own filename validator, and the host rejects a wrong prefix', function (): void {
    $slug = pluginManifest()['slug'];
    $path = (new CustomSkillsPlugin())->migrationsPath();

    expect(fn() => pluginCallValidateMigrationFilenames($slug, $path))->not->toThrow(Throwable::class);

    $good = pluginTempMigrationsDir(["{$slug}_000001_ok.php", "{$slug}_000002_also_ok.php"]);
    $hyphenless = pluginTempMigrationsDir(['customskills_000001_dropped_hyphen.php']);
    $unprefixed = pluginTempMigrationsDir(["{$slug}_000001_ok.php", '000002_no_prefix.php']);

    try {
        expect(fn() => pluginCallValidateMigrationFilenames($slug, $good))->not->toThrow(Throwable::class);
        expect(fn() => pluginCallValidateMigrationFilenames($slug, $hyphenless))
            ->toThrow(SchemaInstallFailedException::class, "Expected filename starting with '{$slug}_'");
        expect(fn() => pluginCallValidateMigrationFilenames($slug, $unprefixed))
            ->toThrow(SchemaInstallFailedException::class);
    } finally {
        pluginRemoveTempDir($good);
        pluginRemoveTempDir($hyphenless);
        pluginRemoveTempDir($unprefixed);
    }
});

it('declares schema version 1 and a migrations path that holds the migration', function (): void {
    $plugin = new CustomSkillsPlugin();
    $path = $plugin->migrationsPath();

    expect($plugin->schemaVersion())->toBe(1)
        ->and(is_dir($path))->toBeTrue()
        ->and(realpath($path))->toBe(realpath(BASE_PATH . '/database/migrations'))
        ->and(glob($path . '/*.php'))->not->toBeEmpty();
});

it('contributes exactly the custom-skill provider, which is a real SkillProviderInterface', function (): void {
    $providers = (new CustomSkillsPlugin())->skillProviders();

    expect($providers)->toBe([CustomSkillProvider::class])
        ->and(class_exists(CustomSkillProvider::class))->toBeTrue()
        ->and(implementsContract(CustomSkillProvider::class, SkillProviderInterface::class))->toBeTrue();
});

it('contributes the manage_skill tool and the custom-skills admin app', function (): void {
    $plugin = new CustomSkillsPlugin();

    expect($plugin->tools())->toBe([ManageSkillTool::class])
        ->and($plugin->apps())->toBe([CustomSkillsApp::class])
        ->and(class_exists(ManageSkillTool::class))->toBeTrue()
        ->and(implementsContract(ManageSkillTool::class, Spora\Tools\ToolInterface::class))->toBeTrue()
        ->and(implementsContract(CustomSkillsApp::class, Spora\Apps\AppInterface::class))->toBeTrue()
        ->and($plugin->getName())->toBe((new CustomSkillsApp())->displayName());
});

it('names the admin app after the manifest slug, with a resolvable entry and non-empty chrome', function (): void {
    $app = new CustomSkillsApp();

    expect($app->name())->toBe('custom-skills')
        ->and($app->name())->toBe(pluginManifest()['slug'])
        ->and($app->entry())->toBe('main.js')
        ->and($app->icon())->toBeString()->not->toBe('');
});

it('declares an accent the host actually renders, and agrees with the manifest', function (): void {
    // Pinned to the host's token list because `tileAccent()` coerces an unknown token to `primary`.
    $accent = (new CustomSkillsApp())->accent();

    expect($accent)->toBeIn(Spora\Apps\AppInterface::ACCENT_TOKENS)
        ->and(pluginManifest()['accent'])->toBe($accent);
});

it('subscribes to the container and route events with methods that exist', function (): void {
    $events = CustomSkillsPlugin::getSubscribedEvents();

    expect(implementsContract(CustomSkillsPlugin::class, EventSubscriberInterface::class))->toBeTrue()
        ->and($events)->toHaveKeys([ContainerBuildingEvent::class, RoutesRegisteringEvent::class])
        ->and($events)->toBe([
            ContainerBuildingEvent::class  => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ]);

    foreach ($events as $listener) {
        expect(method_exists(CustomSkillsPlugin::class, $listener))->toBeTrue();
    }
});

/**
 * The load-time guard. Observing the real throw needs a core without
 * `SkillProviderInterface`, which this suite cannot uninstall, so the test pins
 * the guard's parts: a surviving constructor, the `interface_exists()`
 * condition, and a message naming the missing interface.
 */
it('guards the constructor against a core without the provider seam', function (): void {
    expect(new CustomSkillsPlugin())->toBeInstanceOf(CustomSkillsPlugin::class)
        ->and(interface_exists(SkillProviderInterface::class))->toBeTrue()
        ->and(interface_exists('Spora\Skills\NotARealProviderInterface'))->toBeFalse();

    // The guard's condition, replicated: a current core throws nothing.
    $guardCondition = static fn(string $interface): bool => interface_exists($interface);

    expect($guardCondition(SkillProviderInterface::class))->toBeTrue()
        ->and($guardCondition('Spora\Skills\NotARealProviderInterface'))->toBeFalse()
        ->and(class_exists(PluginLoadFailedException::class))->toBeTrue()
        ->and(implementsContract(PluginLoadFailedException::class, RuntimeException::class))->toBeTrue();

    $constructor = new ReflectionMethod(CustomSkillsPlugin::class, '__construct');
    $source = array_slice(
        explode("\n", (string) file_get_contents((string) $constructor->getFileName())),
        $constructor->getStartLine() - 1,
        $constructor->getEndLine() - $constructor->getStartLine() + 1,
    );
    $body = implode("\n", $source);

    expect($body)->toContain('interface_exists(SkillProviderInterface::class)')
        ->and($body)->toContain('throw new PluginLoadFailedException(')
        ->and(str_replace('\\\\', '\\', $body))->toContain('Spora\Skills\SkillProviderInterface');
});

it('registers exactly the nine contract routes, all behind Auth + CSRF', function (): void {
    [$registrations] = pluginRouteRegistrations(new CustomSkillsPlugin());

    $base = '/api/v1/custom-skills';

    $expected = [
        'GET ' . $base                                  => 'index',
        'POST ' . $base                                 => 'store',
        'GET ' . $base . '/{name}'                      => 'show',
        'PUT ' . $base . '/{name}'                      => 'update',
        'DELETE ' . $base . '/{name}'                   => 'destroy',
        'GET ' . $base . '/{name}/files'                => 'files',
        'GET ' . $base . '/{name}/files/{path}'         => 'file',
        'GET ' . $base . '/{name}/allowlist'            => 'allowlist',
        'POST ' . $base . '/{name}/restore'             => 'restore',
    ];

    $actual = [];
    foreach ($registrations as $registration) {
        $actual[$registration['method'] . ' ' . $registration['route']] = $registration['handler'];
    }

    expect($registrations)->toHaveCount(9)
        ->and(array_keys($actual))->toBe(array_keys($expected));

    foreach ($expected as $signature => $method) {
        $handler = $actual[$signature];

        expect($handler['handler'])->toBe([CustomSkillController::class, $method])
            // Auth before CSRF: reversed, an unauthenticated request reaches the CSRF check.
            ->and($handler['middleware'])->toBe([AuthMiddleware::class, CsrfMiddleware::class]);
    }
});

it('registers no route outside /api/v1/custom-skills', function (): void {
    [$registrations] = pluginRouteRegistrations(new CustomSkillsPlugin());

    foreach ($registrations as $registration) {
        expect($registration['route'])->toStartWith('/api/v1/custom-skills');
    }
});

it('produces a dispatchable route dataset rather than one FastRoute rejects', function (): void {
    [, $recorder] = pluginRouteRegistrations(new CustomSkillsPlugin());

    // `Router` builds its dispatcher from this dataset, so a rejected shape is a boot-time crash.
    expect(fn() => new FastRoute\Dispatcher\GroupCountBased($recorder->getData()))->not->toThrow(Throwable::class);
});

it('binds each plugin interface to its concrete class', function (): void {
    $definitions = pluginContainerDefinitions(new CustomSkillsPlugin());

    $targets = [];
    foreach ($definitions as $id => $helper) {
        $targets[$id] = pluginDefinitionTarget($helper, $id);
    }

    expect($targets[CustomSkillQueryInterface::class])->toBe(CustomSkillQuery::class)
        ->and($targets[CustomSkillWriterInterface::class])->toBe(CustomSkillWriter::class)
        ->and($targets[ToolConfigServiceInterface::class])->toBe(ToolConfigService::class)
        ->and($targets[CustomSkillResource::class])->toBe(CustomSkillResource::class)
        ->and($targets[CustomSkillController::class])->toBe(CustomSkillController::class)
        ->and($targets[ManageSkillTool::class])->toBe(ManageSkillTool::class)
        ->and($targets[SkillComposer::class])->toBe(SkillComposer::class)
        ->and($targets[SkillValidator::class])->toBe(SkillValidator::class)
        // Bound by interface so the atomicity test can substitute a failing scrubber.
        ->and($targets[AllowedSkillsScrubberInterface::class])->toBe(AllowedSkillsScrubber::class)
        ->and($targets[PrincipalService::class])->toBe(PrincipalService::class)
        ->and($targets[PrincipalResolver::class])->toBe(PrincipalResolver::class);
});

/**
 * `SkillProviderRegistry` is core's aggregate of every provider. A plugin binding
 * it would replace that aggregate with one provider alone, emptying the picker.
 */
it('does not bind SkillProviderRegistry, so core\'s provider aggregate survives', function (): void {
    $definitions = pluginContainerDefinitions(new CustomSkillsPlugin());

    expect(array_key_exists(SkillProviderRegistry::class, $definitions))->toBeFalse();

    foreach (array_keys($definitions) as $id) {
        expect($id)->not->toBe(SkillProviderRegistry::class);
    }
});

it('resolves the bound interface graph to the concrete class through a real container', function (): void {
    $builder = new ContainerBuilder();
    (new CustomSkillsPlugin())->onContainerBuilding(new ContainerBuildingEvent($builder));

    // Host-owned, supplied as the boot does, so this resolves the plugin's own bindings.
    $builder->addDefinitions([
        SecurityManagerInterface::class => \DI\factory(
            static fn(): SecurityManager => new SecurityManager(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        ),
        LoggerInterface::class => \DI\factory(static fn(): LoggerInterface => new Monolog\Logger('test')),
    ]);

    $container = $builder->useAutowiring(true)->build();

    expect($container->get(CustomSkillQueryInterface::class))->toBeInstanceOf(CustomSkillQuery::class)
        ->and($container->get(CustomSkillResource::class))->toBeInstanceOf(CustomSkillResource::class)
        ->and($container->get(SkillComposer::class))->toBeInstanceOf(SkillComposer::class);
});

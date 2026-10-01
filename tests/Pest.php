<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Services\AllowedSkillsScrubber;
use Spora\Plugins\CustomSkills\Services\CustomSkillQuery;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriter;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface;
use Spora\Plugins\CustomSkills\Services\SkillAllowlistReader;
use Spora\Plugins\CustomSkills\Services\SkillComposer;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillValidator;
use Symfony\Component\HttpFoundation\Request;

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Duplicates core's `bootAuth` / `jsonRequest` / `simulateLoggedInSession` so
| this suite is self-contained against the symlinked spora-core.
*/

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

// delight-im/auth v9 uses implicit nullable parameter types, and PHP attributes
// the deprecation to the CALLING file — so the vendor path alone cannot filter
// it. The plugin is strict_types throughout, so matching the message is safe.
set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
    if ($errno !== E_DEPRECATED) {
        return false;
    }

    return str_contains($errfile, DIRECTORY_SEPARATOR . 'delight-im' . DIRECTORY_SEPARATOR)
        || str_contains($errstr, 'Implicitly marking parameter');
}, E_DEPRECATED);

function bootAuthLayer(): AuthService
{
    $pdo = Capsule::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false);

    return new AuthService($auth);
}

function jsonRequest(string $method, string $uri, array $body = []): Request
{
    return Request::create(
        $uri,
        strtoupper($method),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $body !== [] ? json_encode($body) : '',
    );
}

function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_LOGGED_IN] = true;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID] = $userId;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_EMAIL] = $email;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USERNAME] = null;
}

function clearSession(): void
{
    $_SESSION = [];
}

function bootAuth(AuthService $authService, string $email = 'test@example.com', string $password = 'Password1!', string $displayName = 'Test User'): int
{
    $userId = $authService->register($email, $password, $displayName);
    simulateLoggedInSession($userId, $email);

    return $userId;
}

function createUserPrincipal(int $userId): int
{
    return (int) new PrincipalService(new PrincipalResolver())->ensureUserPrincipal($userId)->id;
}

function createAgentForPrincipal(int $principalId, string $name = 'Test Agent'): int
{
    return (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $principalId,
        'name'         => $name,
        'max_steps'    => 5,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
}

/**
 * A group plus its principal, and `$userId` as a member with `$role`.
 *
 * @return array{groupId: int, principalId: int}
 */
function createGroupWithPrincipal(int $userId, string $role = 'member', string $name = 'Test Group'): array
{
    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'              => $name,
        // NOT NULL with an FK to `users` since `0067_introduce_principals_and_groups`.
        'created_by_user_id' => $userId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    $principalId = (int) (new PrincipalService(new PrincipalResolver()))->ensureGroupPrincipal($groupId)->id;

    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $userId,
        'role'       => $role,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return ['groupId' => $groupId, 'principalId' => $principalId];
}

/*
|--------------------------------------------------------------------------
| Service graph
|--------------------------------------------------------------------------
|
| Hand-built rather than resolved from a container, so each test states its collaborators.
*/

function toolConfig(): ToolConfigServiceInterface
{
    // A raw 32-byte key, which SecurityManager accepts in place of a key file.
    // The value is irrelevant; the genuine encrypt/decrypt round-trip is not.
    return new ToolConfigService(
        new Spora\Core\SecurityManager(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('test'),
    );
}

function skillComposer(): SkillComposer
{
    return new SkillComposer();
}

/**
 * The provider's read path — takes no `ToolConfigServiceInterface` on purpose:
 * core wires `SkillProviderRegistry` inside the `ToolConfigService` chain, so
 * a settings-service dependency would close a cycle. See {@see SkillAllowlistReader}.
 */
function skillQuery(): CustomSkillQueryInterface
{
    return new CustomSkillQuery(skillComposer(), new SkillValidator());
}

function skillAllowlist(?ToolConfigServiceInterface $toolConfig = null): SkillAllowlistReader
{
    return new SkillAllowlistReader($toolConfig ?? toolConfig());
}

function skillRegistry(CustomSkillQueryInterface $query): SkillProviderRegistry
{
    return new SkillProviderRegistry([new CustomSkillProvider($query)]);
}

function skillWriter(CustomSkillQueryInterface $query, ToolConfigServiceInterface $toolConfig, SkillProviderRegistry $registry): CustomSkillWriterInterface
{
    return new CustomSkillWriter(
        skillComposer(),
        new SkillValidator(),
        $query,
        new AllowedSkillsScrubber($toolConfig),
        $registry,
    );
}

function makeSkill(
    CustomSkillWriterInterface $writer,
    int $principalId,
    string $name = 'test-skill',
    array $overrides = [],
): Spora\Plugins\CustomSkills\Models\CustomSkill {
    return $writer->create($principalId, 0, $overrides + [
        'name'        => $name,
        'description' => "A test skill named {$name}.",
        'body'        => "# Steps\n\n1. Do the thing.\n",
    ], Spora\Plugins\CustomSkills\Models\CustomSkill::PROVENANCE_HUMAN);
}

uses()
    ->beforeEach(function () {
        Spora\Core\Database::resetBootState();
        $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
        $db->boot();
        Capsule::connection()->beginTransaction();

        // The host installer only runs plugin migrations behind a `PluginLoader`, which this suite does not wire.
        foreach (glob(__DIR__ . '/../database/migrations/*.php') ?: [] as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    })
    ->afterEach(function () {
        if (Capsule::connection()->transactionLevel() > 0) {
            Capsule::connection()->rollBack();
        }
        Spora\Core\Database::resetBootState();
        Mockery::close();
    })
    ->in(__DIR__);

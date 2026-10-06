<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Principal;
use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Tools\ManageSkillTool;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\SkillTool;

/**
 * The LLM-facing surface: which operations are offered, which principal a write
 * lands on, and what the approval card and result text may say.
 *
 * These tests used to plant a decoy `$userId` on every write, so that a
 * reintroduced fallback to it would fail a test rather than pass quietly. That
 * tripwire is gone: `execute()` no longer declares the parameter, so the
 * fallback is not expressible at the call site. The guard moved from runtime to
 * compile time - re-adding the parameter is now a PHPStan error against core
 * 0.30.0's four-parameter interface, which is a stronger guarantee than the
 * decoy was.
 */
function toolGraph(): ManageSkillTool
{
    $config = toolConfig();
    $query = skillQuery();

    return new ManageSkillTool(
        skillWriter($query, $config, skillRegistry($query)),
        new PrincipalResolver(),
    );
}

/**
 * A runner user with two genuinely distinct principals: their own, and a group
 * they own. The group is the case D9 is about, so the two must not coincide.
 *
 * @return array{userId: int, principalId: int, groupPrincipalId: int}
 */
function seededRunner(): array
{
    $userId = (int) Capsule::table('users')->insertGetId([
        'email'      => 'runner-' . bin2hex(random_bytes(4)) . '@spora.test',
        'username'   => null,
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => 'Runners',
        'created_by_user_id' => $userId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    return [
        'userId'           => $userId,
        'principalId'      => createUserPrincipal($userId),
        'groupPrincipalId' => (int) (new PrincipalService(new PrincipalResolver()))
            ->ensureGroupPrincipal($groupId)
            ->id,
    ];
}

/**
 * A create call the tool can be handed: the discriminator, and nothing a model would not supply.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function createArguments(string $name = 'alpha', array $overrides = []): array
{
    return $overrides + [
        'action'      => 'create',
        'name'        => $name,
        'description' => "A test skill named {$name}.",
        'body'        => "# Steps\n\n1. Do the thing.\n",
    ];
}

it('offers create and update by default but holds delete off the safe default', function (): void {
    // Read from the attributes: hardcoded literals on both sides cannot fail on drift.
    $declared = [];
    foreach ((new ReflectionClass(ManageSkillTool::class))->getAttributes(ToolOperation::class) as $attribute) {
        /** @var ToolOperation $operation */
        $operation = $attribute->newInstance();
        $declared[$operation->name] = [
            'enabled'  => $operation->enabledByDefault,
            'approval' => $operation->requiresApprovalByDefault,
        ];
    }

    expect($declared)->toBe([
        'create' => ['enabled' => true, 'approval' => true],
        'update' => ['enabled' => true, 'approval' => true],
        // Approval is no substitute for enablement: a delete must be turned on deliberately.
        'delete' => ['enabled' => false, 'approval' => true],
    ]);
});

it('records a created skill as agent provenance', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededRunner();

    $result = toolGraph()->execute(
        createArguments(),
        0,
        null,
        new PrincipalContext($principalId, Principal::TYPE_USER, $userId, $userId),
    );

    expect($result->success)->toBeTrue()
        ->and(CustomSkill::query()->forPrincipal($principalId)->sole()->provenance)
        ->toBe(CustomSkill::PROVENANCE_AGENT);
});

it('returns a failed result naming the taken-name code', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededRunner();
    $tool = toolGraph();
    $context = new PrincipalContext($principalId, Principal::TYPE_USER, $userId, $userId);

    expect($tool->execute(createArguments(), 0, null, $context)->success)->toBeTrue();

    $second = $tool->execute(createArguments(), 0, null, $context);

    expect($second->success)->toBeFalse()
        ->and($second->content)->toContain('SKILL_NAME_TAKEN');
});

it('writes to the principal the execution context resolved', function (): void {
    ['userId' => $userId, 'groupPrincipalId' => $groupPrincipalId] = seededRunner();
    $agentId = createAgentForPrincipal($groupPrincipalId, 'Group Agent');

    $result = toolGraph()->execute(
        createArguments(),
        $agentId,
        null,
        new PrincipalContext($groupPrincipalId, Principal::TYPE_GROUP, $userId, $userId),
    );

    expect($result->success)->toBeTrue()
        ->and(CustomSkill::query()->forPrincipal($groupPrincipalId)->count())->toBe(1);
});

it('falls back to the agent\'s own principal when no context is supplied, never the caller\'s', function (): void {
    ['userId' => $userId, 'principalId' => $principalId, 'groupPrincipalId' => $groupPrincipalId] = seededRunner();
    $agentId = createAgentForPrincipal($groupPrincipalId, 'Group Agent');

    $result = toolGraph()->execute(createArguments(), $agentId, null, null);

    expect($result->success)->toBeTrue()
        ->and(CustomSkill::query()->forPrincipal($groupPrincipalId)->count())->toBe(1)
        // D9: a group agent's skills must not land on the caller's principal, which
        // shares its owner user id.
        ->and(CustomSkill::query()->forPrincipal($principalId)->count())->toBe(0);
});

it('ignores a principal_id argument naming a different principal', function (): void {
    ['userId' => $userId, 'principalId' => $principalId, 'groupPrincipalId' => $groupPrincipalId] = seededRunner();
    $agentId = createAgentForPrincipal($groupPrincipalId, 'Group Agent');

    $result = toolGraph()->execute(
        createArguments('alpha', ['principal_id' => $principalId]),
        $agentId,
        null,
        new PrincipalContext($groupPrincipalId, Principal::TYPE_GROUP, $userId, $userId),
    );

    expect($result->success)->toBeTrue()
        ->and(CustomSkill::query()->forPrincipal($groupPrincipalId)->count())->toBe(1)
        ->and(CustomSkill::query()->forPrincipal($principalId)->count())->toBe(0);
});

it('fails closed when the principal cannot be resolved', function (): void {
    ['userId' => $userId] = seededRunner();

    $result = toolGraph()->execute(
        createArguments(),
        0,
        null,
        new PrincipalContext(0, Principal::TYPE_USER, null, null),
    );

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Cannot resolve a principal')
        ->and(CustomSkill::query()->count())->toBe(0);
});

it('reads a sidecar written through files back through the provider', function (): void {
    ['userId' => $userId, 'principalId' => $principalId, 'groupPrincipalId' => $groupPrincipalId] = seededRunner();
    $context = new PrincipalContext($principalId, Principal::TYPE_USER, $userId, $userId);

    $result = toolGraph()->execute(
        createArguments('alpha', ['files' => ['examples/invoice.md' => "# Invoice"]]),
        0,
        null,
        $context,
    );

    $provider = new CustomSkillProvider(skillQuery());

    expect($result->success)->toBeTrue()
        ->and($provider->getSkillFile('alpha', 'examples/invoice.md', $principalId))->toBe("# Invoice")
        // Same provider, another principal: the read side is the tenant boundary.
        ->and($provider->getSkillFile('alpha', 'examples/invoice.md', $groupPrincipalId))->toBeNull();
});

it('names the agents whose allowlists a delete rewrote', function (): void {
    ['userId' => $userId, 'groupPrincipalId' => $groupPrincipalId] = seededRunner();
    $agentId = createAgentForPrincipal($groupPrincipalId, 'Invoicing Agent');
    $config = toolConfig();
    $context = new PrincipalContext($groupPrincipalId, Principal::TYPE_GROUP, $userId, $userId);

    toolGraph()->execute(createArguments(), $agentId, null, $context);
    $config->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['alpha']]);

    $result = toolGraph()->execute(['action' => 'delete', 'name' => 'alpha'], $agentId, null, $context);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain("Invoicing Agent (#{$agentId})")
        ->and($config->getRawAgentOverride(SkillTool::class, $agentId)['allowed_skills'] ?? null)->toBe([]);
});

it('says plainly when no agent allowlist referenced the deleted skill', function (): void {
    ['userId' => $userId, 'groupPrincipalId' => $groupPrincipalId] = seededRunner();
    $agentId = createAgentForPrincipal($groupPrincipalId, 'Invoicing Agent');
    $context = new PrincipalContext($groupPrincipalId, Principal::TYPE_GROUP, $userId, $userId);

    toolGraph()->execute(createArguments(), $agentId, null, $context);

    $result = toolGraph()->execute(['action' => 'delete', 'name' => 'alpha'], $agentId, null, $context);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('No agent allowlists referenced it.')
        ->and($result->content)->not->toContain('Removed from the allowed_skills of:');
});

it('never renders the body into the description an operator approves', function (): void {
    $marker = 'ZEBRAFISH-9F2C-BODY-MARKER';

    $described = toolGraph()->describeAction(createArguments('alpha', ['body' => "# Steps\n\n{$marker}\n"]));

    expect($described)->not->toContain($marker)
        ->and($described)->toContain('alpha')
        // A byte count, so the operator sees the scale without bloating the card.
        ->and($described)->toContain('B body');
});

it('omits an agent count from the delete description because describeAction never receives the principal', function (): void {
    // A count from that model-supplied id would be unauthenticated, not merely cross-tenant.
    ['groupPrincipalId' => $groupPrincipalId] = seededRunner();

    $described = toolGraph()->describeAction([
        'action'       => 'delete',
        'name'         => 'alpha',
        'principal_id' => $groupPrincipalId,
    ]);

    expect($described)->toContain('alpha')
        // The name carries no digits, so any digit here came from a count or the principal id.
        ->and($described)->not->toMatch('/\d/')
        ->and($described)->not->toContain((string) $groupPrincipalId);
});

it('still reports the counts it can derive from the arguments alone', function (): void {
    // Positive control: byte and file counts are derivable, so absence is a regression.
    expect(toolGraph()->describeAction([
        'action' => 'create',
        'name'   => 'alpha',
        'body'   => str_repeat('x', 120),
        'files'  => ['a.md' => 'A', 'b.md' => 'B'],
    ]))->toContain('120B body')
        ->toContain('2 file(s)');
});

it('names the valid operations when the action is not one it knows', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededRunner();

    $result = toolGraph()->execute(
        ['action' => 'fork', 'name' => 'alpha'],
        0,
        null,
        new PrincipalContext($principalId, Principal::TYPE_USER, $userId, $userId),
    );

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('create')
        ->and($result->content)->toContain('update')
        ->and($result->content)->toContain('delete')
        ->and(CustomSkill::query()->count())->toBe(0);
});

<?php

declare(strict_types=1);

use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\AgentTool\SkillCatalogPresenter;
use Spora\Tools\SkillTool;

/**
 * Principal scoping of custom skills, seen from the agent's side.
 *
 * The two halves of this property are tested in the two repos that own them:
 * the plugin proves its provider isolates real rows across principals, and core
 * proves the skill tool and the `skills` block honour whatever scoping a
 * provider applies. What neither repo can test alone is the seam — an agent on
 * principal A reading the agent-facing surfaces while principal B's rows sit in
 * the same table. Core's equivalent cases drive a stub provider, so they prove
 * the principal is threaded correctly, not that the database agrees.
 *
 * This is the composition: real query, real provider, real registry, real rows.
 * It is what stops a refactor from breaking isolation while both repos stay
 * green.
 */

/**
 * @return array{0: SkillProviderRegistry, 1: Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface}
 */
function realSkillGraph(): array
{
    $query    = skillQuery();
    $config   = toolConfig();
    $registry = skillRegistry($query);

    return [$registry, skillWriter($query, $config, $registry)];
}

function contextForPrincipal(int $principalId): PrincipalContext
{
    return new PrincipalContext($principalId, 'user', $principalId, $principalId);
}

/**
 * The `skills` block an agent's `get_available_tools` would report.
 *
 * @return array<string, mixed>
 */
function skillsBlockFor(SkillProviderRegistry $registry, ?PrincipalContext $context): array
{
    return (new SkillCatalogPresenter($registry, toolConfig(), new PrincipalResolver()))
        ->present(1, null, $context);
}

it('hides another principal\'s custom skill from an agent\'s skills block', function (): void {
    $auth    = bootAuthLayer();
    $tenantA = createUserPrincipal(bootAuth($auth, 'agent-a@example.com'));
    $tenantB = createUserPrincipal(bootAuth($auth, 'agent-b@example.com'));

    [$registry, $writer] = realSkillGraph();
    makeSkill($writer, $tenantA, 'tenant-a-skill');
    makeSkill($writer, $tenantB, 'tenant-b-skill');

    // This is the surface a model reads to decide what it may ask about.
    $seenByA = array_column(skillsBlockFor($registry, contextForPrincipal($tenantA))['visible'], 'name');
    expect($seenByA)->toBe(['tenant-a-skill'])
        ->and($seenByA)->not->toContain('tenant-b-skill');

    $seenByB = array_column(skillsBlockFor($registry, contextForPrincipal($tenantB))['visible'], 'name');
    expect($seenByB)->toBe(['tenant-b-skill'])
        ->and($seenByB)->not->toContain('tenant-a-skill');
});

it('refuses to read another principal\'s custom skill even when the name is allowlisted', function (): void {
    $auth    = bootAuthLayer();
    $tenantA = createUserPrincipal(bootAuth($auth, 'read-a@example.com'));
    $tenantB = createUserPrincipal(bootAuth($auth, 'read-b@example.com'));

    [$registry, $writer] = realSkillGraph();
    makeSkill($writer, $tenantA, 'a-only');
    makeSkill($writer, $tenantB, 'b-only');

    $config = toolConfig();
    $agentA = createAgentForPrincipal($tenantA, 'Agent A');

    // Allowlist BOTH names, so the allowlist cannot be the reason a read is
    // refused. This is the case that matters: `allowed_skills` says the agent
    // may read a skill, never which one it is entitled to see.
    $config->putAgentOverride(SkillTool::class, $agentA, [
        'allowed_skills' => json_encode(['a-only', 'b-only'], JSON_THROW_ON_ERROR),
    ]);

    $tool = new SkillTool($registry, $config, new PrincipalResolver());

    $own = $tool->execute(
        ['action' => 'read', 'name' => 'a-only'],
        $agentA,
        null,
        contextForPrincipal($tenantA),
    );
    expect($own->success)->toBeTrue('the allowlist really does grant the agent its own skill');

    $foreign = $tool->execute(
        ['action' => 'read', 'name' => 'b-only'],
        $agentA,
        null,
        contextForPrincipal($tenantA),
    );
    expect($foreign->success)->toBeFalse('an allowlisted name is still refused across principals')
        ->and($foreign->content)->not->toContain('Tenant B');
});

it('shows an agent nothing when its principal cannot be resolved', function (): void {
    $auth    = bootAuthLayer();
    $tenantA = createUserPrincipal(bootAuth($auth, 'null-principal@example.com'));
    [, $writer] = realSkillGraph();
    makeSkill($writer, $tenantA, 'a-skill');

    [$registry] = realSkillGraph();

    // Fail closed on the agent-facing surface, not only inside the provider:
    // a missing principal must not degrade into "everything is visible".
    expect(skillsBlockFor($registry, null)['visible'])->toBe([]);

    $provider = new CustomSkillProvider(skillQuery());
    expect($provider->getSkills(null))->toBe([])
        ->and($provider->getSkills(0))->toBe([])
        ->and($provider->getSkillDetails('a-skill', null))->toBeNull();
});

it('does not let a same-named skill in one tenant shadow the other in the listing', function (): void {
    $auth    = bootAuthLayer();
    $tenantA = createUserPrincipal(bootAuth($auth, 'dup-a@example.com'));
    $tenantB = createUserPrincipal(bootAuth($auth, 'dup-b@example.com'));

    [$registry, $writer] = realSkillGraph();
    makeSkill($writer, $tenantA, 'shared-name', ['body' => 'Tenant A instructions.']);
    makeSkill($writer, $tenantB, 'shared-name', ['body' => 'Tenant B instructions.']);

    $config = toolConfig();
    $agentA = createAgentForPrincipal($tenantA, 'Dup Agent A');
    $config->putAgentOverride(SkillTool::class, $agentA, [
        'allowed_skills' => json_encode(['shared-name'], JSON_THROW_ON_ERROR),
    ]);

    $blockA = skillsBlockFor($registry, contextForPrincipal($tenantA));
    expect(array_column($blockA['visible'], 'name'))->toBe(['shared-name']);

    $tool  = new SkillTool($registry, $config, new PrincipalResolver());
    $read  = $tool->execute(
        ['action' => 'read', 'name' => 'shared-name'],
        $agentA,
        null,
        contextForPrincipal($tenantA),
    );

    // A name collision must resolve to the calling tenant's own row, never to
    // whichever one the registry happened to bucket first.
    expect($read->success)->toBeTrue()
        ->and($read->content)->toContain('Tenant A instructions.')
        ->and($read->content)->not->toContain('Tenant B instructions.');
});

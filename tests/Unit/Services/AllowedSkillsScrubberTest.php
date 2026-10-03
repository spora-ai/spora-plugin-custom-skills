<?php

declare(strict_types=1);

use Spora\Plugins\CustomSkills\Services\AllowedSkillsScrubberInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SkillTool;

/**
 * D11: deleting a custom skill scrubs its name from the `allowed_skills` of every
 * row carrying it for that principal — the feature's only change to host data. The
 * boundaries are the interesting cases: the JSON-string storage form, shipped
 * skills' entries never touched, other agents left alone, an unallowlisted delete.
 */
function allowlistOf(ToolConfigServiceInterface $config, int $agentId): array
{
    $raw = $config->getRawAgentOverride(SkillTool::class, $agentId)['allowed_skills'] ?? null;

    return is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;
}

it('removes the name from every agent that allowlists it, and names them', function (): void {
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $a = createAgentForPrincipal($principalId, 'Invoicer');
    $b = createAgentForPrincipal($principalId, 'Auditor');
    $unrelated = createAgentForPrincipal($principalId, 'Idempotent');

    $config->putAgentOverride(SkillTool::class, $a, ['allowed_skills' => ['shipped-thing', 'mine']]);
    $config->putAgentOverride(SkillTool::class, $b, ['allowed_skills' => ['mine']]);
    $config->putAgentOverride(SkillTool::class, $unrelated, ['allowed_skills' => ['shipped-thing']]);

    makeSkill($writer, $principalId, 'mine');
    $touched = $writer->delete('mine', $principalId, $userId);

    expect(allowlistOf($config, $a))->toBe(['shipped-thing'])
        ->and(allowlistOf($config, $b))->toBe([])
        ->and(allowlistOf($config, $unrelated))->toBe(['shipped-thing']);

    $agentIds = array_column(array_values(array_filter(
        $touched,
        static fn(array $t): bool => $t['scope'] === 'agent',
    )), 'id');
    sort($agentIds);

    expect($agentIds)->toBe([$a, $b]);
});

it('scrubs a value stored as a JSON string, not just as an array', function (): void {
    // Multi-selects travel through the form layer as JSON strings, so a scrub that
    // only handled arrays would iterate a string, iterate nothing, and pass silently.
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $agentId = createAgentForPrincipal($principalId, 'Stringy');
    $config->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => json_encode(['keep', 'mine'])]);

    // Premise guard: or the test would be asserting nothing.
    $stored = $config->getRawAgentOverride(SkillTool::class, $agentId)['allowed_skills'];
    expect($stored)->toBeString();

    makeSkill($writer, $principalId, 'mine');
    $touched = $writer->delete('mine', $principalId, $userId);

    expect(allowlistOf($config, $agentId))->toBe(['keep'])
        ->and(array_column($touched, 'id'))->toContain($agentId);
});

it('scrubs the principal-level default and reports it as a principal entry', function (): void {
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $config->putPrincipalSettings(SkillTool::class, $principalId, ['allowed_skills' => ['mine', 'other']]);

    makeSkill($writer, $principalId, 'mine');
    $touched = $writer->delete('mine', $principalId, $userId);

    expect($config->getPrincipalSettings(SkillTool::class, $principalId)['allowed_skills'])->toBe(['other'])
        ->and(array_column($touched, 'scope'))->toContain('principal');
});

it('never touches another principal\'s allowlist', function (): void {
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $tenantA = createUserPrincipal($userId);
    $tenantB = createUserPrincipal(bootAuth($auth, 'b@example.com'));
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $foreignAgent = createAgentForPrincipal($tenantB, 'Foreign');
    $config->putAgentOverride(SkillTool::class, $foreignAgent, ['allowed_skills' => ['mine']]);
    $config->putPrincipalSettings(SkillTool::class, $tenantB, ['allowed_skills' => ['mine']]);

    makeSkill($writer, $tenantA, 'mine');
    $writer->delete('mine', $tenantA, $userId);

    expect(allowlistOf($config, $foreignAgent))->toBe(['mine'])
        ->and($config->getPrincipalSettings(SkillTool::class, $tenantB)['allowed_skills'])->toBe(['mine']);
});

it('is a no-op for settings when nobody allowlists the skill', function (): void {
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $agentId = createAgentForPrincipal($principalId, 'Unrelated');
    $config->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['something-else']]);

    makeSkill($writer, $principalId, 'orphan');
    $touched = $writer->delete('orphan', $principalId, $userId);

    expect($touched)->toBe([])
        ->and(allowlistOf($config, $agentId))->toBe(['something-else']);
});

it('leaves other settings on the same override intact', function (): void {
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $agentId = createAgentForPrincipal($principalId, 'Busy');
    $config->putAgentOverride(SkillTool::class, $agentId, [
        'allowed_skills' => ['mine'],
        'other_key'      => 'keep me',
    ]);

    makeSkill($writer, $principalId, 'mine');
    $writer->delete('mine', $principalId, $userId);

    $after = $config->getRawAgentOverride(SkillTool::class, $agentId);
    expect($after['other_key'])->toBe('keep me')
        ->and($after['allowed_skills'])->toBe([]);
});

it('refuses to delete a name that is not a custom skill on this principal', function (): void {
    // Scope safety: a shipped skill is not deletable through this plugin, so its
    // allowlist entries can never be rewritten. An unknown name 404s first.
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $agentId = createAgentForPrincipal($principalId, 'Shipped');
    $config->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['typst']]);

    try {
        $writer->delete('typst', $principalId, $userId);
        $this->fail('delete of a non-existent custom skill must throw');
    } catch (Spora\Plugins\CustomSkills\Exceptions\CustomSkillException $e) {
        expect($e->errorCode)->toBe('SKILL_NOT_FOUND');
    }

    expect(allowlistOf($config, $agentId))->toBe(['typst']);
});

it('leaves the global default alone', function (): void {
    // A global default is not principal-owned: rewriting it reaches every other tenant's agents.
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $config->putGlobalSettings(SkillTool::class, ['allowed_skills' => ['mine']]);

    makeSkill($writer, $principalId, 'mine');
    $writer->delete('mine', $principalId, $userId);

    expect($config->getGlobalSettings(SkillTool::class)['allowed_skills'])->toBe(['mine']);
});

it('reports the blast radius before the delete, via the query read path', function (): void {
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));

    $a = createAgentForPrincipal($principalId, 'Invoicer');
    $config->putAgentOverride(SkillTool::class, $a, ['allowed_skills' => ['mine']]);
    $config->putPrincipalSettings(SkillTool::class, $principalId, ['allowed_skills' => ['mine']]);

    makeSkill($writer, $principalId, 'mine');

    $radius = skillAllowlist($config)->forSkill('mine', $principalId);
    expect(array_column($radius, 'scope'))->toBe(['principal', 'agent'])
        ->and($radius[1]['id'])->toBe($a)
        ->and($radius[1]['name'])->toBe('Invoicer');

    // And what it reports is what the delete actually rewrites.
    $touched = $writer->delete('mine', $principalId, $userId);
    expect($touched)->toBe($radius);
});

it('rolls the delete back when the scrub cannot complete', function (): void {
    // The scrub and the row delete share a transaction. If the scrub throws, the
    // skill must survive — otherwise its allowlist entries are gone from no one
    // and present everywhere.
    $auth = bootAuthLayer();
    $userId = bootAuth($auth);
    $principalId = createUserPrincipal($userId);
    $config = toolConfig();
    $query = skillQuery();
    $registry = skillRegistry($query);

    $exploding = new class implements AllowedSkillsScrubberInterface {
        public function scrub(string $name, int $principalId): array
        {
            throw new RuntimeException('scrub failed');
        }

        public function transactionally(callable $operation): mixed
        {
            return Illuminate\Database\Capsule\Manager::connection()->transaction($operation);
        }
    };

    $writer = new Spora\Plugins\CustomSkills\Services\CustomSkillWriter(
        new Spora\Plugins\CustomSkills\Services\SkillComposer(),
        new Spora\Skills\SkillValidator(),
        $query,
        $exploding,
        $registry,
    );

    makeSkill($writer, $principalId, 'atomic');

    try {
        $writer->delete('atomic', $principalId, $userId);
        $this->fail('delete must propagate the scrub failure');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('scrub failed');
    }

    expect($query->findForPrincipal('atomic', $principalId))->not->toBeNull();
});

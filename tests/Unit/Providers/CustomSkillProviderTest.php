<?php

declare(strict_types=1);

use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Services\SkillComposer;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;

/**
 * The provider's half of the boundary. Not that the methods work — the writer test
 * covers that — but that none of them quietly widens, which the happy path cannot show.
 */
it('fails closed on every method when the principal is null', function (): void {
    $query = skillQuery();
    $provider = new CustomSkillProvider($query);
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    makeSkill(skillWriter($query, toolConfig(), skillRegistry($query)), $principalId, 'closed-off');

    expect($provider->getSkills(null))->toBe([])
        ->and($provider->getSkillDetails('closed-off', null))->toBeNull()
        ->and($provider->getSkillFiles('closed-off', null))->toBeNull()
        ->and($provider->getSkillFile('closed-off', SkillComposer::ENTRY_FILE, null))->toBeNull();
});

it('fails closed on the unresolvable-principal sentinel 0', function (): void {
    $query = skillQuery();
    $provider = new CustomSkillProvider($query);
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    makeSkill(skillWriter($query, toolConfig(), skillRegistry($query)), $principalId, 'sentinel');

    expect($provider->getSkills(0))->toBe([])
        ->and($provider->getSkills(-7))->toBe([])
        ->and($provider->getSkillDetails('sentinel', 0))->toBeNull()
        ->and($provider->getSkillFiles('sentinel', 0))->toBeNull();
});

it('never returns another principal\'s skills', function (): void {
    $auth = bootAuthLayer();
    $tenantA = createUserPrincipal(bootAuth($auth, 'a@example.com'));
    $tenantB = createUserPrincipal(bootAuth($auth, 'b@example.com'));

    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));
    makeSkill($writer, $tenantA, 'tenant-a-only');
    makeSkill($writer, $tenantB, 'tenant-b-only');

    $provider = new CustomSkillProvider($query);

    $namesForA = array_map(static fn($s) => $s->name, $provider->getSkills($tenantA));
    expect($namesForA)->toBe(['tenant-a-only']);

    // The name exists, but not for this principal: indistinguishable from nowhere.
    expect($provider->getSkillDetails('tenant-b-only', $tenantA))->toBeNull()
        ->and($provider->getSkillFiles('tenant-b-only', $tenantA))->toBeNull()
        ->and($provider->getSkillFile('tenant-b-only', SkillComposer::ENTRY_FILE, $tenantA))->toBeNull();
});

it('distinguishes an unknown skill from a known one with no sidecars', function (): void {
    $config = toolConfig();
    $query = skillQuery();
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    makeSkill(skillWriter($query, $config, skillRegistry($query)), $principalId, 'bare');

    $provider = new CustomSkillProvider($query);

    // Known skill, no sidecars: still carries the synthesised entry, and is not null.
    $known = $provider->getSkillFiles('bare', $principalId);
    expect($known)->not->toBeNull()
        ->and($known)->toHaveCount(1)
        ->and($known[0]['path'])->toBe(SkillComposer::ENTRY_FILE);

    expect($provider->getSkillFiles('no-such-skill', $principalId))->toBeNull();
});

it('returns the entry file first and sidecars after, in stable order', function (): void {
    $config = toolConfig();
    $query = skillQuery();
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    makeSkill(skillWriter($query, $config, skillRegistry($query)), $principalId, 'listed', [
        'files' => ['zebra.md' => 'z', 'alpha/deep.md' => 'a'],
    ]);

    $provider = new CustomSkillProvider($query);
    $paths = array_column($provider->getSkillFiles('listed', $principalId) ?? [], 'path');

    expect($paths)->toBe([SkillComposer::ENTRY_FILE, 'alpha/deep.md', 'zebra.md']);
});

it('rejects a traversal path without consulting the database', function (): void {
    $config = toolConfig();
    $query = skillQuery();
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    makeSkill(skillWriter($query, $config, skillRegistry($query)), $principalId, 'guarded', [
        'files' => ['ok.md' => 'fine'],
    ]);

    $provider = new CustomSkillProvider($query);

    foreach (['../secrets.md', 'ok/../../secrets.md', '/etc/passwd', 'a\\b.md', 'a//b.md', '.'] as $attempt) {
        expect($provider->getSkillFile('guarded', $attempt, $principalId))
            ->toBeNull("provider must refuse '{$attempt}'");
    }

    expect($provider->getSkillFile('guarded', 'ok.md', $principalId))->toBe('fine');
});

it('refuses to materialise a sidecar over the read cap', function (): void {
    $config = toolConfig();
    $query = skillQuery();
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $writer = skillWriter($query, $config, skillRegistry($query));

    // Written directly so the row can exceed the cap: the reader has to hold the
    // line independently, because a provider is plugin-supplied code.
    $skill = makeSkill($writer, $principalId, 'oversized');
    Illuminate\Database\Capsule\Manager::table('custom_skill_files')->insert([
        'custom_skill_id' => $skill->id,
        'path'            => 'huge.md',
        'content'         => str_repeat('x', SkillProviderInterface::MAX_FILE_BYTES + 1),
        'bytes'           => SkillProviderInterface::MAX_FILE_BYTES + 1,
    ]);

    $provider = new CustomSkillProvider($query);

    // Still listed, so the caller can tell "too big" from "not a file"…
    $listed = array_column($provider->getSkillFiles('oversized', $principalId) ?? [], 'path');
    expect($listed)->toContain('huge.md');

    // …but the content is not returned.
    expect($provider->getSkillFile('oversized', 'huge.md', $principalId))->toBeNull();
});

it('treats an empty sidecar as a legal body, not as not-found', function (): void {
    $config = toolConfig();
    $query = skillQuery();
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    makeSkill(skillWriter($query, $config, skillRegistry($query)), $principalId, 'empties', [
        'files' => ['blank.md' => ''],
    ]);

    $provider = new CustomSkillProvider($query);

    expect($provider->getSkillFile('empties', 'blank.md', $principalId))->toBe('');
    expect($provider->getSkillFile('empties', 'absent.md', $principalId))->toBeNull();
});

it('reports source per skill and never overwrites it with the provider label', function (): void {
    $config = toolConfig();
    $query = skillQuery();
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    makeSkill(skillWriter($query, $config, skillRegistry($query)), $principalId, 'labelled');

    $provider = new CustomSkillProvider($query);
    $summaries = $provider->getSkills($principalId);

    expect($provider->source())->toBe('custom-skills')
        ->and($summaries[0]->source)->toBe('custom-skills')
        ->and($summaries[0]->slug)->toBe($summaries[0]->name);
});

it('returns a descriptor for a skill with no metadata, license or compatibility', function (): void {
    // `SkillDescriptor::$metadata` is a non-nullable `array` while the model's cast
    // reads an unset column back as null — a TypeError until the provider coerced it.
    $config = toolConfig();
    $query = skillQuery();
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    makeSkill(skillWriter($query, $config, skillRegistry($query)), $principalId, 'plain');

    $descriptor = (new CustomSkillProvider($query))->getSkillDetails('plain', $principalId);

    expect($descriptor)->not->toBeNull()
        ->and($descriptor->metadata)->toBe([])
        ->and($descriptor->compatibility)->toBeNull()
        ->and($descriptor->allowedTools)->toBeNull()
        ->and($descriptor->warnings)->toBe([])
        ->and($descriptor->body)->toContain('Do the thing');
});

it('runs the provider through a real registry without shadowing a shipped skill', function (): void {
    $config = toolConfig();
    $query = skillQuery();
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    makeSkill(skillWriter($query, toolConfig(), skillRegistry($query)), $principalId, 'mine');

    // A stand-in for core's `FilesystemSkillProvider`, ordered first as core does.
    $shipped = new class implements SkillProviderInterface {
        public function source(): string
        {
            return 'filesystem';
        }

        public function getSkills(?int $principalId): array
        {
            return [new Spora\Skills\SkillSummary(name: 'shipped', description: 'd', source: 'core')];
        }

        public function getSkillDetails(string $name, ?int $principalId): ?Spora\Skills\SkillDescriptor
        {
            return $name === 'shipped'
                ? new Spora\Skills\SkillDescriptor(summary: new Spora\Skills\SkillSummary(name: 'shipped', description: 'd', source: 'core'))
                : null;
        }

        public function getSkillFiles(string $name, ?int $principalId): ?array
        {
            return $name === 'shipped' ? [['path' => 'SKILL.md', 'bytes' => 10]] : null;
        }

        public function getSkillFile(string $name, string $path, ?int $principalId): ?string
        {
            return $name === 'shipped' && $path === 'SKILL.md' ? "# shipped\n" : null;
        }
    };

    $registry = new SkillProviderRegistry([$shipped, new CustomSkillProvider($query)]);

    $names = array_map(static fn($s) => $s->name, $registry->getSkills($principalId));
    expect($names)->toBe(['shipped', 'mine'])
        ->and($registry->sources())->toBe(['filesystem', 'custom-skills']);

    // Both file reads route to the provider that owns the name.
    expect($registry->getSkillFile('shipped', 'SKILL.md', $principalId))->toBe("# shipped\n")
        ->and($registry->getSkillFile('mine', SkillComposer::ENTRY_FILE, $principalId))
        ->toContain('name: mine');
});

it('hands a plugin a query that answers only for the requested principal', function (): void {
    // Guards the seam the provider depends on: losing the predicate returns every skill.
    $config = toolConfig();
    $query = skillQuery();
    $writer = skillWriter($query, $config, skillRegistry($query));
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    makeSkill($writer, $principalId, 'listed');

    expect($query->listForPrincipal($principalId))->toHaveCount(1)
        ->and($query->listForPrincipal($principalId + 9999))->toHaveCount(0);
});

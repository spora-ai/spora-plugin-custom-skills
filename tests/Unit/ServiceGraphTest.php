<?php

declare(strict_types=1);

use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Services\CustomSkillLimits;
use Spora\Skills\SkillProviderInterface;

/** The service graph the other suites build on, asserted once so a broken constructor fails as one error. */
it('resolves the service graph against a live schema', function (): void {
    $config = toolConfig();
    $query = skillQuery();
    $registry = skillRegistry($query);
    $writer = skillWriter($query, $config, $registry);

    expect($writer)->toBeInstanceOf(Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface::class)
        ->and($registry->sources())->toBe([CustomSkillProvider::SOURCE])
        ->and(CustomSkillLimits::SKILLS_PER_PRINCIPAL)->toBe(25)
        ->and(SkillProviderInterface::MAX_FILE_BYTES)->toBe(50_000);
});

it('runs the plugin migration and creates both tables', function (): void {
    $schema = Illuminate\Database\Capsule\Manager::schema();

    expect($schema->hasTable('custom_skills'))->toBeTrue()
        ->and($schema->hasTable('custom_skill_files'))->toBeTrue();
});

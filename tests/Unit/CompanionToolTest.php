<?php

declare(strict_types=1);

use Spora\Plugins\Skeleton\CompanionTool;
use Spora\Tools\Attributes\Tool;

it('declares the #Tool attribute on CompanionTool', function (): void {
    $reflection = new ReflectionClass(CompanionTool::class);

    $attrs = $reflection->getAttributes(Tool::class);

    expect($attrs)->toHaveCount(1);
});

it('recommends the bundled companion-skill slug', function (): void {
    $reflection = new ReflectionClass(CompanionTool::class);

    $args = $reflection->getAttributes(Tool::class)[0]->getArguments();

    expect($args['recommendsSkills'] ?? [])->toContain('companion-skill');
})->skip(
    ! property_exists(Tool::class, 'recommendsSkills'),
    'Awaiting spora-core v0.29.0 — #[Tool(recommendsSkills: ...)] ships in PR spora-core#269. '
        . 'Re-enable by deleting this guard once the operator has upgraded.',
);

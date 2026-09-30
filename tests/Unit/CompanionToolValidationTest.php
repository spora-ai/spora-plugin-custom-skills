<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Plugins\Skeleton\CompanionTool;
use Spora\Plugins\Skeleton\Tests\Support\Exceptions\TestTempDirectoryCreationException;
use Spora\Services\ToolConfigNameResolver;
use Spora\Services\ToolsRecommendsSkillsValidator;
use Spora\Skills\SkillScanner;

/**
 * @return array{0: SkillScanner, 1: callable(): void, 2: string}
 */
function makeCompanionSkillScanner(): array
{
    $abs = sys_get_temp_dir() . '/spora_companion_scan_' . uniqid('', true);
    if (!is_dir($abs) && !mkdir($abs, 0o755, true) && !is_dir($abs)) {
        throw new TestTempDirectoryCreationException("Cannot create test directory: {$abs}");
    }

    $cleanup = static function () use ($abs): void {
        if (!is_dir($abs)) {
            return;
        }
        $files = [];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $files[] = $f->getRealPath();
        }
        foreach ($files as $f) {
            @is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($abs);
    };

    return [
        new SkillScanner([['path' => $abs, 'source' => 'skeleton']]),
        $cleanup,
        $abs,
    ];
}

test('the bundled companion-skill satisfies ToolsRecommendsSkillsValidator', function (): void {
    if (! class_exists(ToolsRecommendsSkillsValidator::class)) {
        return;
    }

    [$scanner, $cleanup, $root] = makeCompanionSkillScanner();
    try {
        $dir = $root . '/companion-skill';
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new TestTempDirectoryCreationException("Cannot create skill directory: {$dir}");
        }
        file_put_contents(
            $dir . '/SKILL.md',
            "---\nname: companion-skill\ndescription: Demo skill bundled by CompanionTool.\n---\n\n# Companion skill",
        );

        $resolver = new ToolConfigNameResolver(new NullLogger(), [CompanionTool::class]);
        $validator = new ToolsRecommendsSkillsValidator($resolver, $scanner);

        expect($validator->validate())->toBe([]);
    } finally {
        $cleanup();
    }
})->skip(
    ! class_exists(ToolsRecommendsSkillsValidator::class),
    'Awaiting spora-core v0.29.0 — ToolsRecommendsSkillsValidator ships in PR spora-core#269. '
        . 'Re-enable by deleting this guard once the operator has upgraded.',
);

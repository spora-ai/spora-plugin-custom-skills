<?php

declare(strict_types=1);

use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Symfony\Component\Yaml\Yaml;

/**
 * The synthesised `SKILL.md` has to survive the reader core uses on the filesystem
 * path, {@see Spora\Skills\SkillScanner}: it takes everything between the leading
 * `---` and the first `\n---` after it, and `Yaml::parse`s that block. A dump that
 * does not end in a newline therefore loses its closing delimiter.
 */
function frontmatterBlock(string $entryFile): array
{
    $closeAt = strpos(substr($entryFile, 3), "\n---");

    return Yaml::parse(substr($entryFile, 3, $closeAt));
}

function composedSkill(array $attributes, string $body = "# Steps\n\n1. Do the thing.\n"): string
{
    $skill = new CustomSkill();
    $skill->forceFill($attributes + ['body' => $body]);

    return skillComposer()->compose($skill);
}

it('emits both delimiters on their own lines', function (): void {
    $entry = composedSkill(['name' => 'plain', 'description' => 'One line.']);

    expect($entry)->toStartWith("---\n")->toContain("\n---\n\n")
        ->and(frontmatterBlock($entry))->toBe([
            'name' => 'plain',
            'description' => 'One line.',
        ]);
});

it('keeps a multi-line description parseable', function (): void {
    // The regression: `DUMP_MULTI_LINE_LITERAL_BLOCK` ends the dump without a
    // newline, so the `---` landed on the last content line and the whole entry
    // file failed to parse — for every skill whose description spans two lines.
    $description = "First line\nSecond line";
    $entry = composedSkill(['name' => 'multi', 'description' => $description]);

    expect($entry)->toContain("Second line\n---\n")
        ->and(frontmatterBlock($entry))->toBe([
            'name' => 'multi',
            'description' => $description,
        ]);
});

it('keeps a multi-line metadata value parseable', function (): void {
    $metadata = ['note' => "a\n---\nb"];
    $entry = composedSkill(['name' => 'meta', 'description' => 'One line.', 'metadata' => $metadata]);

    expect(frontmatterBlock($entry))->toBe([
        'name' => 'meta',
        'description' => 'One line.',
        'metadata' => $metadata,
    ]);
});

it('leaves a body that opens with a delimiter alone', function (): void {
    $body = "---\nnot frontmatter, just body\n";
    $entry = composedSkill(['name' => 'body', 'description' => 'One line.'], $body);

    expect($entry)->toEndWith($body)
        ->and(frontmatterBlock($entry))->toBe([
            'name' => 'body',
            'description' => 'One line.',
        ]);
});

it('counts the composed entry, closing newline included', function (): void {
    $skill = new CustomSkill();
    $skill->forceFill([
        'name' => 'multi',
        'description' => "First line\nSecond line",
        'body' => "# Steps\n",
    ]);

    expect(skillComposer()->entryBytes($skill))->toBe(strlen(skillComposer()->compose($skill)));
});

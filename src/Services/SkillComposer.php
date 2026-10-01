<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders a {@see CustomSkill} row as the `SKILL.md` the provider hands the LLM, and as
 * the frontmatter array `SkillValidator` checks.
 *
 * Synthesised rather than stored: the frontmatter is already normalised into columns, so a
 * second copy could disagree. Dumped through `symfony/yaml`, the parser core's
 * `SkillScanner` reads with, so an entry round-trips unchanged.
 */
final class SkillComposer
{
    /** The synthesised entry file. Always index 0 of a skill's listing. */
    public const ENTRY_FILE = 'SKILL.md';

    /**
     * Core's validator expects the hyphenated `allowed-tools`; the tool parameter and column
     * are `allowed_tools`. The rename happens here, the boundary both sides agree on.
     *
     * @return array<string, mixed>
     */
    public function frontmatter(CustomSkill $skill): array
    {
        $frontmatter = [
            'name' => $skill->name,
            'description' => $skill->description,
        ];

        if ($skill->license !== null && $skill->license !== '') {
            $frontmatter['license'] = $skill->license;
        }
        if ($skill->compatibility !== null && $skill->compatibility !== '') {
            $frontmatter['compatibility'] = $skill->compatibility;
        }
        if ($skill->allowed_tools !== null && $skill->allowed_tools !== '') {
            $frontmatter['allowed-tools'] = $skill->allowed_tools;
        }
        // An unset `metadata` reads back as null, not `[]` — it is an array cast. Testing
        // `!== []` alone would emit `metadata: null`, which the validator rejects.
        if ($skill->metadata !== null && $skill->metadata !== []) {
            $frontmatter['metadata'] = $skill->metadata;
        }

        return $frontmatter;
    }

    public function compose(CustomSkill $skill): string
    {
        $yaml = Yaml::dump($this->frontmatter($skill), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        // `DUMP_MULTI_LINE_LITERAL_BLOCK` leaves the dump without its final newline,
        // which would glue the closing `---` onto the last content line and leave an
        // entry file `SkillScanner` cannot parse.
        if (!str_ends_with($yaml, "\n")) {
            $yaml .= "\n";
        }

        return "---\n{$yaml}---\n\n" . $skill->body;
    }

    public function entryBytes(CustomSkill $skill): int
    {
        return strlen($this->compose($skill));
    }
}

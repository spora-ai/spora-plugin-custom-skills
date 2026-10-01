<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders a {@see CustomSkill} row as the `SKILL.md` the provider hands the LLM,
 * and as the frontmatter array `SkillValidator` checks.
 *
 * Synthesised rather than stored because the frontmatter is already normalised
 * into columns: storing it too would create a second writable copy that could
 * disagree. Dumped through `symfony/yaml`, the parser core's `SkillScanner` reads
 * files with, so an entry round-trips through that scanner unchanged.
 */
final class SkillComposer
{
    /** The synthesised entry file. Always index 0 of a skill's listing. */
    public const ENTRY_FILE = 'SKILL.md';

    /**
     * Core's validator expects the hyphenated `allowed-tools`; the tool parameter
     * and column are `allowed_tools` as the friendlier shape for an LLM. The
     * rename happens here, the one boundary both sides agree on.
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
        // An unset `metadata` reads back as null, not `[]` — it is an array
        // cast. Testing `!== []` alone would emit `metadata: null`, which the
        // validator rejects, so an ordinary skill would fail to write.
        if ($skill->metadata !== null && $skill->metadata !== []) {
            $frontmatter['metadata'] = $skill->metadata;
        }

        return $frontmatter;
    }

    public function compose(CustomSkill $skill): string
    {
        $yaml = Yaml::dump($this->frontmatter($skill), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        return "---\n{$yaml}---\n\n" . $skill->body;
    }

    public function entryBytes(CustomSkill $skill): int
    {
        return strlen($this->compose($skill));
    }
}

<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

/**
 * Hard caps on custom-skill content (decision D15), enforced as errors with named codes
 * rather than warnings. Core's only body bound, `SkillValidator::BODY_SOFT_BYTE_LIMIT`, is a
 * warning and `isValid()` checks errors only, so on the filesystem path nothing bounds a
 * body — and a principal-scoped store is a denial-of-service surface.
 *
 * `MAX_FILE_BYTES` is deliberately not restated here: the provider enforces it, so one
 * number cannot drift.
 */
final class CustomSkillLimits
{
    public const SKILLS_PER_PRINCIPAL = 25;

    /** Sidecar files per skill, excluding the synthesised `SKILL.md`. */
    public const FILES_PER_SKILL = 20;

    /** Every byte of a skill: body, synthesised frontmatter and sidecars. */
    public const TOTAL_BYTES = 200_000;

    /** `description` length, matching `SkillValidator::DESCRIPTION_MAX_LENGTH`. */
    public const DESCRIPTION_LENGTH = 1024;
}

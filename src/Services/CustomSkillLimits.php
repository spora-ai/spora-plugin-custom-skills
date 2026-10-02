<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

/**
 * Hard caps on custom-skill content (decision D15), enforced as errors with named codes
 * rather than warnings. Core's only body bound, `SkillValidator::BODY_SOFT_BYTE_LIMIT`, is a
 * warning and `isValid()` checks errors only, so on the filesystem path nothing bounds a
 * body — and a principal-scoped store is a denial-of-service surface.
 *
 * `MAX_FILE_BYTES` is deliberately not restated here, so one number cannot drift — but
 * note *who* enforces it, because it is not the provider. The provider's
 * `getSkillFile()` does not cap the synthesised `SKILL.md`; the writer does, on every
 * write, because that file is composed from columns and so never passes through the
 * sidecar validation. This comment previously claimed the provider enforced it, which
 * is why an over-cap entry file could be written and then be unopenable.
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

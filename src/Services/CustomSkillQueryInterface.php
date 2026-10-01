<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Spora\Plugins\CustomSkills\Models\CustomSkill;

/**
 * Read side of custom skills, split from the writer so each consumer loads only its half.
 */
interface CustomSkillQueryInterface
{
    /**
     * @return list<CustomSkill>
     */
    public function listForPrincipal(int $principalId): array;

    /**
     * A skill the principal owns, or null — ownership is the only test.
     */
    public function findForPrincipal(string $name, int $principalId): ?CustomSkill;

    /**
     * The synthesised entry file first, then sidecars in path order — `SKILL.md` is always
     * index 0.
     *
     * @return list<array{path: string, bytes: int}>
     */
    public function fileListing(CustomSkill $skill): array;

    /**
     * Raw content for one path from {@see self::fileListing()}, or null when the path is not
     * a member — an empty body is legal, so null is the only "not a member" signal.
     */
    public function fileContent(CustomSkill $skill, string $path): ?string;

    /**
     * `SkillValidator` warnings as the skill stands, so the editor needs no round-trip.
     *
     * @return list<array{code: string, severity: string, message: string, path?: string}>
     */
    public function warnings(CustomSkill $skill): array;
}

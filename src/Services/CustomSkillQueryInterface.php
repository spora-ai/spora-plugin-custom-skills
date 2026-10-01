<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Spora\Plugins\CustomSkills\Models\CustomSkill;

/**
 * Read side of custom skills, split from the writer so each consumer loads only
 * the half it calls — one combined class would put every method on every
 * consumer's class-loading path.
 */
interface CustomSkillQueryInterface
{
    /**
     * @return list<CustomSkill>
     */
    public function listForPrincipal(int $principalId): array;

    /**
     * A skill the principal owns, or null. Ownership is the only test — a
     * principal never sees another's rows.
     */
    public function findForPrincipal(string $name, int $principalId): ?CustomSkill;

    /**
     * The synthesised entry file first, then sidecars in path order. `SKILL.md`
     * is always index 0 so a client can render it without searching.
     *
     * @return list<array{path: string, bytes: int}>
     */
    public function fileListing(CustomSkill $skill): array;

    /**
     * Raw content for one path from {@see self::fileListing()}, or null when
     * the path is not a member. An empty body is legal, so null is the only
     * "not a member" signal.
     */
    public function fileContent(CustomSkill $skill, string $path): ?string;

    /**
     * `SkillValidator` warnings as the skill currently stands, so the editor can
     * show them without a second round-trip.
     *
     * @return list<array{code: string, severity: string, message: string, path?: string}>
     */
    public function warnings(CustomSkill $skill): array;
}

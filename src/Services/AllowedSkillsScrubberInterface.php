<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

/**
 * The `allowed_skills` scrub, behind an interface so the writer can be tested
 * against a scrub that fails: the atomicity guarantee is only observable by
 * making the scrub throw, and a `final` class cannot be made to throw on demand.
 */
interface AllowedSkillsScrubberInterface
{
    /**
     * Remove `$name` from every `allowed_skills` for `$principalId` that
     * carries it, and report what changed.
     *
     * @return list<array{id: int, name: string|null, scope: 'agent'|'principal'}>
     */
    public function scrub(string $name, int $principalId): array;

    /**
     * Run `$operation` and roll it back as a unit.
     *
     * @template T
     *
     * @param callable():T $operation
     * @return T
     */
    public function transactionally(callable $operation): mixed;
}

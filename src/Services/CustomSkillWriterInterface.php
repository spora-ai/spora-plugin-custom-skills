<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Spora\Plugins\CustomSkills\Models\CustomSkill;

/**
 * Write side of custom skills: create, update, delete, and one-step restore.
 * Split from the read side so the `manage_skill` tool does not pull the
 * settings-cascade decryption into every call.
 */
interface CustomSkillWriterInterface
{
    /**
     * @param array<string, mixed> $input `name`, `description`, `body`, and
     *        optionally `license`, `compatibility`, `allowed_tools`,
     *        `metadata` and `files` (path => content, replacing the whole set).
     *
     * @throws \Spora\Plugins\CustomSkills\Exceptions\CustomSkillException
     */
    public function create(int $principalId, int $actorUserId, array $input, string $provenance): CustomSkill;

    /**
     * @param array<string, mixed> $input As {@see self::create()}, minus `name`.
     *
     * @throws \Spora\Plugins\CustomSkills\Exceptions\CustomSkillException
     */
    public function update(string $name, int $principalId, int $actorUserId, array $input, string $provenance): CustomSkill;

    /**
     * Delete the skill and scrub its name from the principal's `allowed_skills`,
     * atomically.
     *
     * @return list<array{id: int, name: string|null, scope: 'agent'|'principal'}> The rows rewritten.
     * @throws \Spora\Plugins\CustomSkills\Exceptions\CustomSkillException
     */
    public function delete(string $name, int $principalId, int $actorUserId): array;

    /**
     * Roll back to `previous_snapshot`, snapshotting the current state first so
     * a restore is itself undoable.
     *
     * @throws \Spora\Plugins\CustomSkills\Exceptions\CustomSkillException
     */
    public function restore(string $name, int $principalId, int $actorUserId, string $provenance): CustomSkill;
}

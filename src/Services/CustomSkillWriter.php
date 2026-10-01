<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Spora\Plugins\CustomSkills\Exceptions\CustomSkillException;
use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Models\CustomSkillFile;
use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillValidator;

/**
 * Create / update / delete / restore for principal-owned custom skills.
 *
 * `create` refuses a taken name and `update` an unknown one: an implicit upsert would let a
 * mistyped `create` overwrite work.
 */
final class CustomSkillWriter implements CustomSkillWriterInterface
{
    /**
     * The persistence allowlist (D19): the host's `SchemaValidator` silently permits
     * undeclared keys, so this list is the boundary; {@see CustomSkill::$fillable} is the
     * second layer. `files` is a relation, deliberately absent.
     *
     * @var list<string>
     */
    private const WRITABLE_COLUMNS = [
        'name',
        'description',
        'license',
        'compatibility',
        'allowed_tools',
        'metadata',
        'body',
    ];

    public function __construct(
        private readonly SkillComposer $composer,
        private readonly SkillValidator $validator,
        private readonly CustomSkillQueryInterface $query,
        private readonly AllowedSkillsScrubberInterface $scrubber,
        private readonly SkillProviderRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public function create(int $principalId, int $actorUserId, array $input, string $provenance): CustomSkill
    {
        $this->assertPrincipal($principalId);
        $this->assertSkillBudget($principalId);

        $name = $this->requiredName($input);
        $files = $this->validatedFiles($input['files'] ?? null);

        if ($this->query->findForPrincipal($name, $principalId) !== null) {
            throw CustomSkillException::nameTaken($name);
        }
        $this->assertNotShipped($name);

        $attributes = $this->columnAttributes($input, ['name' => $name]);
        $this->assertTotalBudget($attributes, $files);

        $this->assertFrontmatter($name, $attributes, $input['body'] ?? '');

        $skill = new CustomSkill();
        $skill->forceFill($attributes);
        $skill->principal_id = $principalId;
        $skill->provenance = $provenance;
        $skill->created_by_user_id = self::actorOrNull($actorUserId);
        $skill->updated_by_user_id = self::actorOrNull($actorUserId);
        $skill->save();

        // An absent `files` is an empty set here; on update the same null means "leave it alone".
        $this->replaceFiles($skill, $files ?? []);

        return $skill->refresh();
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $name, int $principalId, int $actorUserId, array $input, string $provenance): CustomSkill
    {
        $this->assertPrincipal($principalId);

        $skill = $this->query->findForPrincipal($name, $principalId);
        if ($skill === null) {
            throw CustomSkillException::notFound($name);
        }

        // A rename would orphan every `allowed_skills` entry naming it; delete + create is explicit.
        if (isset($input['name']) && trim((string) $input['name']) !== $name) {
            throw CustomSkillException::validation(
                "name cannot be changed by update. Delete '{$name}' and create '"
                . trim((string) $input['name']) . "' instead.",
            );
        }

        $files = $this->validatedFiles($input['files'] ?? null);

        $merged = array_merge($this->snapshotAttributes($skill), $this->columnAttributes($input, []));
        $this->assertTotalBudget($merged, $files);
        $this->assertFrontmatter($name, $merged, $merged['body'] ?? '');

        // Snapshot before `forceFill` — afterwards `restore()` would re-apply the
        // current state and the one-step undo would exist and do nothing.
        $previous = $this->snapshot($skill);

        $skill->forceFill($merged);
        $skill->previous_snapshot = $previous;
        $skill->provenance = $provenance;
        $skill->updated_by_user_id = self::actorOrNull($actorUserId);
        $skill->save();

        if ($files !== null) {
            $this->replaceFiles($skill, $files);
        }

        return $skill->refresh();
    }

    /**
     * @return list<array{id: int, name: string|null, scope: 'agent'|'principal'}>
     */
    public function delete(string $name, int $principalId, int $actorUserId): array
    {
        $this->assertPrincipal($principalId);

        $skill = $this->query->findForPrincipal($name, $principalId);
        if ($skill === null) {
            throw CustomSkillException::notFound($name);
        }

        // One transaction with the scrub: a skill outliving its allowlist entries is what D11 prevents.
        return $this->scrubber->transactionally(function () use ($skill, $name, $principalId, $actorUserId): array {
            $touched = $this->scrubber->scrub($name, $principalId);

            $skill->updated_by_user_id = self::actorOrNull($actorUserId);
            $skill->save();
            $skill->delete();

            return $touched;
        });
    }

    public function restore(string $name, int $principalId, int $actorUserId, string $provenance): CustomSkill
    {
        $this->assertPrincipal($principalId);

        $skill = $this->query->findForPrincipal($name, $principalId);
        if ($skill === null) {
            throw CustomSkillException::notFound($name);
        }

        $snapshot = $skill->previous_snapshot;
        if (!is_array($snapshot) || !array_key_exists('body', $snapshot)) {
            throw CustomSkillException::noPreviousVersion($name);
        }

        // Snapshot live state first, so restore is itself undoable — one level of history.
        $skill->previous_snapshot = $this->snapshot($skill);

        $files = is_array($snapshot['files'] ?? null) ? $snapshot['files'] : [];
        $attributes = array_intersect_key(
            $this->columnAttributes($snapshot, []),
            array_flip(self::WRITABLE_COLUMNS),
        );
        $attributes['name'] = $name;

        $this->assertTotalBudget($attributes, $files);
        $this->assertFrontmatter($name, $attributes, $attributes['body'] ?? '');

        $skill->forceFill($attributes);
        $skill->provenance = $provenance;
        $skill->updated_by_user_id = self::actorOrNull($actorUserId);
        $skill->save();

        $this->replaceFiles($skill, $files);

        return $skill->refresh();
    }

    private function assertPrincipal(int $principalId): void
    {
        if ($principalId <= 0) {
            throw CustomSkillException::validation('An unresolvable principal cannot own a custom skill.');
        }
    }

    /**
     * The acting user, or null when no human is behind the run: a worker signals that with
     * `0`, which the `users` foreign key forbids.
     */
    private static function actorOrNull(int $actorUserId): ?int
    {
        return $actorUserId > 0 ? $actorUserId : null;
    }

    private function assertSkillBudget(int $principalId): void
    {
        $count = CustomSkill::query()->forPrincipal($principalId)->count();
        if ($count >= CustomSkillLimits::SKILLS_PER_PRINCIPAL) {
            throw CustomSkillException::limitReached(CustomSkillLimits::SKILLS_PER_PRINCIPAL);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requiredName(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw CustomSkillException::validation('name is required.');
        }

        return $name;
    }

    /**
     * Reject a name a shipped skill owns. Asked with a `null` principal: core's
     * `FilesystemSkillProvider` ignores the parameter and is first in the registry, so a hit
     * is always a shipped skill — one the registry would drop silently.
     */
    private function assertNotShipped(string $name): void
    {
        if ($this->registry->getSkillDetails($name, null) !== null) {
            throw CustomSkillException::nameReserved($name);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $defaults Values for keys the caller omitted.
     * @return array<string, mixed>
     */
    private function columnAttributes(array $input, array $defaults): array
    {
        $merged = array_merge($defaults, $input);
        $attributes = array_intersect_key($merged, array_flip(self::WRITABLE_COLUMNS));

        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = trim((string) $attributes['name']);
        }
        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = $this->normalisedDescription($attributes['description']);
        }
        if (array_key_exists('body', $attributes)) {
            $attributes['body'] = (string) $attributes['body'];
        }
        if (array_key_exists('metadata', $attributes)) {
            $attributes['metadata'] = $this->normalisedMetadata($attributes['metadata']);
        }
        foreach (['license', 'compatibility', 'allowed_tools'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $value = $attributes[$key] === null ? null : trim((string) $attributes[$key]);
                $attributes[$key] = $value === '' ? null : $value;
            }
        }

        return $attributes;
    }

    /**
     * Trimmed, and bounded before it reaches the column.
     *
     * @throws CustomSkillException
     */
    private function normalisedDescription(mixed $raw): string
    {
        $description = trim((string) $raw);
        if (mb_strlen($description) > CustomSkillLimits::DESCRIPTION_LENGTH) {
            throw CustomSkillException::descriptionTooLong(CustomSkillLimits::DESCRIPTION_LENGTH);
        }

        return $description;
    }

    /**
     * `null`, or a map of scalar values stringified. `''` reads as "unset": the column
     * is nullable and an HTML form round-trip sends it empty. A boolean becomes
     * `true`/`false` rather than `1`/`""`, which a bare `(string)` cast would give.
     *
     * @return array<string, string>|null
     * @throws CustomSkillException
     */
    private function normalisedMetadata(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_array($raw)) {
            throw CustomSkillException::validation('metadata must be an object of string values.');
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw CustomSkillException::validation("metadata.{$key} must be a scalar value.");
            }
            if (is_bool($value)) {
                $out[(string) $key] = $value ? 'true' : 'false';
                continue;
            }
            $out[(string) $key] = (string) $value;
        }

        return $out;
    }

    /**
     * Sidecar files, or null when the caller supplied none — on update null means "leave the
     * existing set alone", distinct from `[]` meaning "remove them all".
     *
     * @return array<string, string>|null
     */
    private function validatedFiles(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            throw CustomSkillException::validation('files must be an object of path => content.');
        }
        if ($raw !== [] && array_is_list($raw)) {
            throw CustomSkillException::validation('files must be an object of path => content, not a list.');
        }
        if (count($raw) > CustomSkillLimits::FILES_PER_SKILL) {
            throw CustomSkillException::tooManyFiles(CustomSkillLimits::FILES_PER_SKILL);
        }

        $out = [];
        foreach ($raw as $path => $content) {
            $path = (string) $path;

            // `SKILL.md` is synthesised, so a row under that path would be an unwritable second copy.
            if ($path === SkillComposer::ENTRY_FILE) {
                throw CustomSkillException::validation(
                    "'" . SkillComposer::ENTRY_FILE . "' is the entry file and is generated from the skill's fields; "
                    . 'it cannot be supplied in `files`.',
                );
            }
            if (!CustomSkillProvider::isSafePath($path)) {
                throw CustomSkillException::validation("files path '{$path}' is not a plain relative path.");
            }
            if (!is_string($content)) {
                throw CustomSkillException::validation("files['{$path}'] must be a string.");
            }
            if (strlen($content) > SkillProviderInterface::MAX_FILE_BYTES) {
                throw CustomSkillException::fileTooLarge($path, SkillProviderInterface::MAX_FILE_BYTES);
            }

            $out[$path] = $content;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, string>|null $files
     */
    private function assertTotalBudget(array $attributes, ?array $files): void
    {
        $total = strlen((string) ($attributes['body'] ?? ''));

        foreach ($files ?? [] as $content) {
            $total += strlen($content);
        }

        // The synthesised frontmatter counts too, estimated from column widths: the cap
        // rejects before the write, and composing it would defeat that.
        $total += strlen((string) ($attributes['name'] ?? ''))
            + strlen((string) ($attributes['description'] ?? ''))
            + 128;

        if ($total > CustomSkillLimits::TOTAL_BYTES) {
            throw CustomSkillException::totalSizeExceeded(CustomSkillLimits::TOTAL_BYTES, $total);
        }
    }

    /**
     * Core's validator, so a custom skill is held to the same rules as a shipped one.
     *
     * @param array<string, mixed> $attributes
     */
    private function assertFrontmatter(string $name, array $attributes, string $body): void
    {
        $probe = new CustomSkill();
        $probe->forceFill($attributes);

        $result = $this->validator->validate($this->composer->frontmatter($probe), $body, $name);

        if (!$result->isValid()) {
            throw CustomSkillException::skillInvalid($result->errors(), $result->warnings());
        }
    }

    /**
     * Replaces the whole set. Not atomic today: only `delete()` opens a transaction,
     * so a failure mid-loop leaves the parent row updated with a partial sidecar set.
     *
     * @param array<string, string> $files
     */
    private function replaceFiles(CustomSkill $skill, array $files): void
    {
        CustomSkillFile::query()->where('custom_skill_id', $skill->id)->delete();

        foreach ($files as $path => $content) {
            // Assigned explicitly: the parent key is this method's to decide, so it is not
            // in `$fillable`.
            $file = new CustomSkillFile();
            $file->custom_skill_id = (int) $skill->id;
            $file->path = $path;
            $file->content = $content;
            $file->bytes = strlen($content);
            $file->save();
        }
    }

    /**
     * The current state, in the shape {@see self::restore()} reads back.
     *
     * @return array<string, mixed>
     */
    private function snapshot(CustomSkill $skill): array
    {
        return $this->snapshotAttributes($skill) + [
            'files' => self::filesAsMap($skill),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotAttributes(CustomSkill $skill): array
    {
        return [
            'name'           => $skill->name,
            'description'    => $skill->description,
            'license'        => $skill->license,
            'compatibility'  => $skill->compatibility,
            'allowed_tools'  => $skill->allowed_tools,
            'metadata'       => $skill->metadata,
            'body'           => $skill->body,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function filesAsMap(CustomSkill $skill): array
    {
        $map = [];
        foreach (CustomSkillFile::query()->where('custom_skill_id', $skill->id)->orderBy('path')->get() as $file) {
            $map[$file->path] = $file->content;
        }

        return $map;
    }
}

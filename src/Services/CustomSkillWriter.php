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
 * Strict on purpose: `create` fails on an existing name, `update` fails on a
 * missing one, and an implicit upsert would let a mistyped `create` silently
 * overwrite work. Validation runs cheapest-first so the most specific failure
 * is the one reported.
 */
final class CustomSkillWriter implements CustomSkillWriterInterface
{
    /**
     * The persistence allowlist (D19) — the host's `SchemaValidator` silently
     * permits undeclared keys, so the boundary has to be this list;
     * {@see CustomSkill::$fillable} is the second, structural layer. `files` is
     * a relation and is deliberately absent.
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

        // An absent `files` is an empty set here; on update the same null means
        // "leave the existing set alone".
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

        // A rename would orphan every `allowed_skills` entry pointing at it.
        // Delete + create is explicit, and the D11 scrub cleans up.
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

        // Pre-update, before `forceFill`: `snapshot()` reads the model's columns,
        // so taking it afterwards would have `restore()` re-apply the current
        // state and the one-step undo would exist and do nothing.
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

        // The scrub and the deletion share one transaction: a skill outliving
        // its own allowlist entries is the exact silent state D11 prevents.
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

        // Snapshot the live state first, so restore is itself undoable. One
        // level of history; a `custom_skill_revisions` table is a follow-up.
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
     * The acting user, or null for a run with no human behind it — a scheduled
     * or worker write signals that with `0`, and the `*_by_user_id` columns are
     * nullable precisely for it. Writing `0` would violate the `users` foreign
     * key, so no scheduled run could author a skill at all.
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
     * Reject a name a shipped skill already owns. Asked with a `null` principal:
     * core's `FilesystemSkillProvider` ignores the parameter and is first in
     * the class list, so a hit is always a shipped skill. The registry would
     * drop the duplicate anyway — this gives the author the reason instead of a
     * skill that silently never appears.
     */
    private function assertNotShipped(string $name): void
    {
        if ($this->registry->getSkillDetails($name, null) !== null) {
            throw CustomSkillException::nameReserved($name);
        }
    }

    /**
     * Column values for persistence, restricted to {@see self::WRITABLE_COLUMNS}.
     *
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
            $description = trim((string) $attributes['description']);
            if (mb_strlen($description) > CustomSkillLimits::DESCRIPTION_LENGTH) {
                throw CustomSkillException::descriptionTooLong(CustomSkillLimits::DESCRIPTION_LENGTH);
            }
            $attributes['description'] = $description;
        }
        if (array_key_exists('body', $attributes)) {
            $attributes['body'] = (string) $attributes['body'];
        }
        if (array_key_exists('metadata', $attributes)) {
            $metadata = $attributes['metadata'];
            if ($metadata === null || $metadata === '') {
                $attributes['metadata'] = null;
            } elseif (!is_array($metadata)) {
                throw CustomSkillException::validation('metadata must be an object of string values.');
            } else {
                $attributes['metadata'] = self::stringMap($metadata, 'metadata');
            }
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
     * @param array<mixed> $raw
     * @return array<string, string>
     */
    private static function stringMap(array $raw, string $field): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw CustomSkillException::validation("{$field}.{$key} must be a scalar value.");
            }
            $out[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $out;
    }

    /**
     * Sidecar files, or null when the caller supplied none — which on update
     * means "leave the existing set alone", distinct from `[]` meaning
     * "remove them all".
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

            // `SKILL.md` is synthesised from the columns, so a row under that
            // path would be a second, unwritable copy that collides in the listing.
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

        // The synthesised frontmatter is part of the stored skill, so budget it
        // too — estimated from known column widths rather than composing the
        // real file, since the cap's job is to reject before the write.
        $total += strlen((string) ($attributes['name'] ?? ''))
            + strlen((string) ($attributes['description'] ?? ''))
            + 128;

        if ($total > CustomSkillLimits::TOTAL_BYTES) {
            throw CustomSkillException::totalSizeExceeded(CustomSkillLimits::TOTAL_BYTES, $total);
        }
    }

    /**
     * The full frontmatter check, using core's validator so a custom skill is
     * held to exactly the rules a shipped one is.
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
     * Replace the whole sidecar set, inside the parent write's transaction so a
     * skill can never be left half-applied.
     *
     * @param array<string, string> $files
     */
    private function replaceFiles(CustomSkill $skill, array $files): void
    {
        CustomSkillFile::query()->where('custom_skill_id', $skill->id)->delete();

        foreach ($files as $path => $content) {
            // Assigned explicitly rather than through `create()`: not in
            // `$fillable`, because the parent key is this method's to decide.
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

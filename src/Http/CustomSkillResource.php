<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Http;

use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use Spora\Plugins\CustomSkills\Services\SkillComposer;
use stdClass;

/**
 * Shapes a {@see CustomSkill} for the REST surface. One serialiser rather than a
 * presenter per endpoint, because `GET …/{name}`, `POST` and `PUT` return the
 * *same* skill shape in the frozen contract — a client editing from a list entry
 * and from a detail view must not handle two variants.
 */
final class CustomSkillResource
{
    public function __construct(private readonly CustomSkillQueryInterface $query) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(CustomSkill $skill): array
    {
        $files = $this->query->fileListing($skill);
        $warnings = $this->query->warnings($skill);
        $body = (string) $skill->body;

        return [
            'id'                 => (int) $skill->id,
            'principal_id'       => (int) $skill->principal_id,
            'name'               => $skill->name,
            'slug'               => $skill->name,
            'description'        => $skill->description,
            'license'            => $skill->license,
            'compatibility'      => $skill->compatibility,
            'allowed_tools'      => $skill->allowed_tools,
            // The contract says `metadata` is always an object (`{}` when
            // unset); a PHP empty array encodes as `[]`, and `stdClass` is the
            // only way to make `json_encode` emit `{}`.
            'metadata'           => $skill->metadata === null || $skill->metadata === []
                ? new stdClass()
                : $skill->metadata,
            'body'               => $body,
            'body_bytes'         => strlen($body),
            'provenance'         => $skill->provenance,
            'created_by_user_id' => $skill->created_by_user_id === null ? null : (int) $skill->created_by_user_id,
            'updated_by_user_id' => $skill->updated_by_user_id === null ? null : (int) $skill->updated_by_user_id,
            'created_at'         => $skill->created_at->format('Y-m-d H:i:s'),
            'updated_at'         => $skill->updated_at->format('Y-m-d H:i:s'),
            'files'              => $files,
            'has_previous'       => is_array($skill->previous_snapshot),
            'warnings'           => $warnings,
            'warning_count'      => count($warnings),
        ];
    }

    /**
     * @param list<array{path: string, bytes: int}> $files
     */
    public static function entryIsFirst(array $files): bool
    {
        return ($files[0]['path'] ?? null) === SkillComposer::ENTRY_FILE;
    }
}

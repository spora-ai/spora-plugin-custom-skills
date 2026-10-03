<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Http;

use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use stdClass;

/**
 * Shapes a {@see CustomSkill} for the REST surface. One serialiser, not a presenter per
 * endpoint: `GET …/{name}`, `POST` and `PUT` return the *same* shape in the frozen contract.
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
            // The contract says `metadata` is always an object; an empty PHP array encodes
            // as `[]`, and `stdClass` is the only way to emit `{}`.
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
            // When the rollback copy was taken. Null on a row written before this
            // existed, which is the same shape as "no previous version" for the
            // label and different for the button, so both are reported.
            'previous_at'        => is_array($skill->previous_snapshot)
                && is_string($skill->previous_snapshot['captured_at'] ?? null)
                    ? $skill->previous_snapshot['captured_at']
                    : null,
            'previous_by'        => is_array($skill->previous_snapshot)
                && is_int($skill->previous_snapshot['captured_by'] ?? null)
                    ? $skill->previous_snapshot['captured_by']
                    : null,
            'warnings'           => $warnings,
            'warning_count'      => count($warnings),
        ];
    }
}

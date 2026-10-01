<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spora\Models\Principal;

/**
 * A skill authored for one principal, stored as columns rather than a directory.
 *
 * `$fillable` excludes every server-assigned column — `id`, `principal_id`, `provenance`,
 * the `*_by_user_id` columns, the timestamps — because the host's `SchemaValidator`
 * silently permits undeclared keys, so identity must be structurally unwritable.
 *
 * `body` is the `SKILL.md` body only; the fence is synthesised on read
 * ({@see \Spora\Plugins\CustomSkills\Services\SkillComposer}), so a round-trip is safe.
 *
 * @property int $id
 * @property int $principal_id
 * @property string $name
 * @property string $description
 * @property string|null $license
 * @property string|null $compatibility
 * @property string|null $allowed_tools
 * @property array<string, string>|null $metadata Null until set: the `array` cast reads a raw NULL as null.
 * @property string $body
 * @property array<string, mixed>|null $previous_snapshot
 * @property string $provenance 'human' | 'agent'
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CustomSkillFile> $files
 * @property-read Principal|null $principal
 */
final class CustomSkill extends Model
{
    public const PROVENANCE_HUMAN = 'human';
    public const PROVENANCE_AGENT = 'agent';

    protected $table = 'custom_skills';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'license',
        'compatibility',
        'allowed_tools',
        'metadata',
        'body',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'principal_id'       => 'integer',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'metadata'           => 'array',
        'previous_snapshot'  => 'array',
        'provenance'         => 'string',
    ];

    /**
     * @return HasMany<CustomSkillFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(CustomSkillFile::class, 'custom_skill_id');
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /**
     * @param Builder<CustomSkill> $query
     * @return Builder<CustomSkill>
     */
    public function scopeForPrincipal(Builder $query, int $principalId): Builder
    {
        return $query->where('principal_id', $principalId);
    }
}

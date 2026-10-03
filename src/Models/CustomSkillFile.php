<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sidecar file belonging to a {@see CustomSkill} skill.
 *
 * `bytes` is stored so the provider can enforce
 * {@see \Spora\Skills\SkillProviderInterface::MAX_FILE_BYTES} from an indexed column check
 * before `content` is read — measuring a `longText` blob means materialising it. `SKILL.md`
 * is not a row: it is synthesised from the columns.
 *
 * @property int $id
 * @property int $custom_skill_id
 * @property string $path
 * @property string $content
 * @property int $bytes
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read CustomSkill|null $skill
 */
final class CustomSkillFile extends Model
{
    protected $table = 'custom_skill_files';

    /** @var list<string> */
    protected $fillable = [
        'path',
        'content',
        'bytes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'custom_skill_id' => 'integer',
        'bytes'           => 'integer',
    ];

    /**
     * @return BelongsTo<CustomSkill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(CustomSkill::class, 'custom_skill_id');
    }
}

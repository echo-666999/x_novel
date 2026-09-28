<?php

namespace App\Models;

use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use Database\Factories\NovelOutlineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * 保存不可变大纲版本头、来源与关系化子节点入口。
 */
#[Fillable([
    'novel_id',
    'version',
    'status',
    'source',
    'schema_version',
    'title',
    'summary',
    'must_include',
    'must_not_include',
    'checksum',
    'source_artifact_id',
    'based_on_outline_id',
    'created_by',
    'applied_at',
])]
class NovelOutline extends Model
{
    /** @use HasFactory<NovelOutlineFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (NovelOutline $outline): void {
            // Chapter Plan 会冻结精确 Outline Version，内容原地修改会破坏历史可解释性。
            $allowed = ['status', 'applied_at', 'updated_at'];
            if (array_diff(array_keys($outline->getDirty()), $allowed) !== []) {
                throw new LogicException('Novel Outline versions are immutable; create a new version instead.');
            }
        });

        static::deleting(function (NovelOutline $outline): void {
            // 已被章节规划引用的版本属于生成证据链，必须永久保留。
            if ($outline->chapterPlans()->exists()) {
                throw new LogicException('Novel Outline versions referenced by Chapter Plans cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return BelongsTo<NovelOutline, $this> */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_outline_id');
    }

    /** @return HasMany<NovelOutline, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'based_on_outline_id')->orderBy('version');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<GenerationArtifact, $this> AI 版本唯一对应的最终蓝图 Artifact。 */
    public function sourceArtifact(): BelongsTo
    {
        return $this->belongsTo(GenerationArtifact::class, 'source_artifact_id');
    }

    /** @return HasMany<NovelOutlineVolume, $this> 按顺序读取版本内的 Volume。 */
    public function volumes(): HasMany
    {
        return $this->hasMany(NovelOutlineVolume::class)->orderBy('sequence');
    }

    /** @return HasMany<NovelOutlineArc, $this> 按顺序读取版本内的 Arc。 */
    public function arcs(): HasMany
    {
        return $this->hasMany(NovelOutlineArc::class)->orderBy('sequence');
    }

    /** @return HasMany<NovelOutlineBeat, $this> 按顺序读取版本内的 Beat。 */
    public function beats(): HasMany
    {
        return $this->hasMany(NovelOutlineBeat::class)->orderBy('sequence');
    }

    /** @return HasMany<NovelOutlineMilestone, $this> 按顺序读取版本内的 Milestone。 */
    public function milestones(): HasMany
    {
        return $this->hasMany(NovelOutlineMilestone::class)->orderBy('sequence');
    }

    /** @return HasMany<ChapterPlan, $this> */
    public function chapterPlans(): HasMany
    {
        return $this->hasMany(ChapterPlan::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => NovelOutlineStatus::class,
            'source' => NovelOutlineSource::class,
            'schema_version' => 'integer',
            'must_include' => 'array',
            'must_not_include' => 'array',
            'source_artifact_id' => 'integer',
            'based_on_outline_id' => 'integer',
            'created_by' => 'integer',
            'applied_at' => 'datetime',
        ];
    }
}

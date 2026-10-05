<?php

namespace App\Domain\Demand;

use App\Models\Grade;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلب مادة غير متوفّرة — صفٌّ لكل تقاطع، بعدّاد لا بتكرار.
 */
class MaterialRequest extends Model
{
    /** الحالات المسموحة — وكل واحدة قرار، لا وسم قراءة. */
    public const STATUSES = ['new', 'planned', 'published', 'declined'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * بصمة التقاطع المطلوب.
     *
     * الوسيلة والملاحظة خارج البصمة عمدًا: معلّمان يطلبان الشيء نفسه طلبٌ
     * واحد بشدّة اثنين، لا طلبان متمايزان لأن بريديهما مختلفان.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fingerprintFor(array $attributes): string
    {
        return hash('sha256', json_encode([
            'stage' => $attributes['stage_id'] ?? null,
            'grade' => $attributes['grade_id'] ?? null,
            'subject' => $attributes['subject_id'] ?? null,
            'semester' => $attributes['semester_id'] ?? null,
            'kind' => $attributes['kind'] ?? null,
            'query' => $attributes['query'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

<?php

namespace App\Domain\Demand;

use App\Models\Grade;
use App\Models\Kind;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * تسجيل الطلب الفائت — وتحويله من رسالة إلى رقم.
 *
 * قاعدتان تحكمان هذه الخدمة:
 *
 *  · **التصنيف يُحلّ إلى كيانات أو يُهمَل.** سلسلة لا يعرفها الكتالوج ليست
 *    «قيمة أخرى» تُخزَّن كما جاءت، بل حقلٌ لم يُملأ. تخزين النصّ الحرّ يجعل
 *    «سادس» و«الصف السادس» طلبين، والجدول كلّه موجود ليَعُدّ.
 *
 *  · **طلب فارغ ليس طلبًا.** بلا تصنيف واحد على الأقل ولا ملاحظة ولا استعلام،
 *    لا يوجد ما يُقاس — فيُرفض بدل أن يملأ التقرير بصفوف لا تقول شيئًا.
 */
final class DemandService
{
    /** أقصى ما يُقبل من الملاحظة — والباقي يُقتطع لا يُرفض الطلب لأجله. */
    private const NOTE_LIMIT = 1000;

    /**
     * يسجّل طلبًا، ويدمجه مع طلب سابق لنفس التقاطع.
     *
     * @param  array<string, mixed>  $input  سلاسل التصنيف كما أرسلها المتجر
     *
     * @throws EmptyDemandException طلبٌ بلا أي إشارة يمكن قياسها
     */
    public function record(array $input): MaterialRequest
    {
        $attributes = [
            'stage_id' => $this->resolve(Stage::class, $input['stage'] ?? null),
            'grade_id' => $this->resolve(Grade::class, $input['grade'] ?? null),
            'subject_id' => $this->resolve(Subject::class, $input['subject'] ?? null),
            'semester_id' => $this->resolve(Semester::class, $input['semester'] ?? null),
            'kind' => $this->resolveKind($input['kind'] ?? null),
            'query' => $this->trimOrNull($input['query'] ?? null, 200),
        ];

        $note = $this->trimOrNull($input['note'] ?? null, self::NOTE_LIMIT);

        $hasTaxonomy = $attributes['stage_id'] !== null
            || $attributes['grade_id'] !== null
            || $attributes['subject_id'] !== null
            || $attributes['semester_id'] !== null
            || $attributes['kind'] !== null;

        if (! $hasTaxonomy && $note === null && $attributes['query'] === null) {
            throw new EmptyDemandException;
        }

        [$contact, $contactKind] = $this->splitContact($input['contact'] ?? null);

        $fingerprint = MaterialRequest::fingerprintFor($attributes);
        $source = ($input['source'] ?? 'form') === 'zero_result' ? 'zero_result' : 'form';

        /*
         * الدمج داخل معاملة مع قفل الصف: نموذجان يُرسلان في اللحظة نفسها
         * لنفس التقاطع يجب أن يزيدا العدّاد مرّتين، لا أن يتسابقا على إنشاء
         * صفّين يكسر أحدهما قيد التفرّد.
         */
        return DB::transaction(function () use ($attributes, $note, $contact, $contactKind, $fingerprint, $source) {
            $existing = MaterialRequest::query()
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'hits' => $existing->hits + 1,
                    'last_seen_at' => now(),
                    // آخر ملاحظة ووسيلة تواصل تُحفظان: الأحدث هو من ينتظر ردًّا.
                    'note' => $note ?? $existing->note,
                    'contact' => $contact ?? $existing->contact,
                    'contact_kind' => $contact !== null ? $contactKind : $existing->contact_kind,
                ])->save();

                return $existing;
            }

            return MaterialRequest::query()->create($attributes + [
                'note' => $note,
                'contact' => $contact,
                'contact_kind' => $contactKind,
                'source' => $source,
                'status' => 'new',
                'fingerprint' => $fingerprint,
                'hits' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        });
    }

    /**
     * يحلّ سلسلة تصنيف إلى معرّفها، أو `null` إن لم يعرفها الكتالوج.
     *
     * @param  class-string<Model>  $model
     */
    private function resolve(string $model, mixed $slug): ?int
    {
        $slug = $this->trimOrNull($slug, 120);
        if ($slug === null) {
            return null;
        }

        $id = $model::query()->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** النوع يُطابق مفردة الكتالوج نفسها — وإلّا فلا نوع. */
    private function resolveKind(mixed $value): ?string
    {
        $value = $this->trimOrNull($value, 120);
        if ($value === null) {
            return null;
        }

        // مسار المتجر يكتب المسافة شرطةً؛ الاثنان يُقبلان والمخزَّن هو المفردة.
        $known = Kind::query()->pluck('slug');
        foreach ($known as $slug) {
            if ($slug === $value || str_replace(' ', '-', (string) $slug) === $value) {
                return (string) $slug;
            }
        }

        return null;
    }

    /**
     * يفصل وسيلة التواصل عن نوعها.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitContact(mixed $value): array
    {
        $value = $this->trimOrNull($value, 190);
        if ($value === null) {
            return [null, null];
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return [$value, 'email'];
        }

        // رقم سعودي أو دولي: تُحتفظ الأرقام و«+» وحدها.
        $digits = preg_replace('/[^\d+]/', '', $value) ?? '';
        if (strlen(preg_replace('/\D/', '', $digits) ?? '') >= 9) {
            return [$digits, 'phone'];
        }

        // ليست بريدًا ولا رقمًا: تُهمَل بدل أن تُخزَّن كوسيلة لا تصل.
        return [null, null];
    }

    private function trimOrNull(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}

<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Models\IntegrationCredential;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * خزنة الاعتمادات — الكاتب الوحيد، والقارئ الوحيد للسرّ.
 *
 * ─── العقد ───
 *
 *   put()      يكتب أو يدوّر — ويُعيد **معاينة** لا قيمة.
 *   reveal()   يفكّ التعمية — للخادم وحده، ولا تُنادى من وحدة تحكّم.
 *   revoke()   يُبطل ولا يمحو.
 *   listFor()  يعرض ما يجوز عرضه: الظاهر كاملًا، والسرّ مقنَّعًا.
 *
 * ─── لماذا لا يُرَدّ السرّ بعد الحفظ ───
 *
 * «أرِني المفتاح» طلبٌ معقول ظاهريًّا وخطيرٌ عمليًّا: يجعل كلّ جلسةٍ مسروقة
 * تُخرج كلّ مفاتيح المتجر دفعةً واحدة، ويجعل المفتاح يمرّ في سجلّ وكيلٍ
 * وذاكرة متصفّحٍ ولقطة شاشة. فيُعرض مرّةً واحدة لحظة الإدخال — من يد صاحبه
 * إلى عينه — ثمّ لا يعود. ومن نسيه يُدوّره؛ والتدوير أرخص من التسريب.
 *
 * ─── ولماذا لا تُكتب القيمة في التدقيق ───
 *
 * سجلّ التدقيق يُقرأ بصلاحيةٍ أوسع من صلاحية المفاتيح، ويُصدَّر، ويُنسخ.
 * فيُسجَّل **أنّ** المفتاح تغيّر ومن غيّره ومتى وبصمته — لا هو.
 */
final class CredentialVault
{
    /** أقصر سرٍّ مقبول: أقصر منه خطأُ لصقٍ لا مفتاح. */
    private const MIN_SECRET = 8;

    /**
     * يكتب اعتمادًا أو يدوّره.
     *
     * @return array<string, mixed> معاينةٌ آمنة — لا قيمة سرّيّة فيها.
     *
     * @throws ValidationException
     */
    public function put(
        string $provider,
        string $environment,
        string $field,
        string $value,
        ?int $actorId,
    ): array {
        $this->assertKnown($provider, $environment, $field);

        $value = trim($value);
        $secret = IntegrationCatalogue::isSecret($provider, $field);
        $spec = IntegrationCatalogue::fields($provider)[$field];

        if ($value === '') {
            throw ValidationException::withMessages(['value' => ['القيمة مطلوبة.']]);
        }

        if ($secret && mb_strlen($value) < self::MIN_SECRET) {
            throw ValidationException::withMessages([
                'value' => ['القيمة أقصر من أن تكون مفتاحًا — راجع ما لُصق.'],
            ]);
        }

        /*
         * البادئة تُفحص حين يُعلنها المزوّد.
         *
         * الخلط الأشيع عمليًّا: لصقُ `pk_` في خانة السرّ أو `sk_` في خانة
         * النشر. الأوّل يُعطّل الدفع صامتًا، والثاني **يُسرّب المفتاح السرّيّ
         * إلى متصفّح كلّ زبون**. وهو خطأٌ لا يُصدر رسالةً من المزوّد.
         */
        if (isset($spec['prefix']) && ! str_starts_with($value, $spec['prefix'])) {
            throw ValidationException::withMessages([
                'value' => ["القيمة يجب أن تبدأ بـ«{$spec['prefix']}» — تحقّق أنّك لصقت الحقل الصحيح."],
            ]);
        }

        /*
         * مفتاح `live` في بيئة `test` يُحصّل مالًا حقيقيًّا في تجربة.
         * والعكس يُعطّل الإنتاج. البادئة تكشف الاثنين حين يستعملها المزوّد.
         */
        $this->assertEnvironmentMatches($environment, $value);

        $fingerprint = hash('sha256', $value);
        $now = now();

        return DB::transaction(function () use (
            $provider, $environment, $field, $value, $secret, $actorId, $fingerprint, $now,
        ) {
            $existing = IntegrationCredential::query()
                ->where('provider', $provider)
                ->where('environment', $environment)
                ->where('field', $field)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'provider' => $provider,
                'environment' => $environment,
                'field' => $field,
                'is_secret' => $secret,
                'value_public' => $secret ? null : $value,
                'value_encrypted' => $secret ? Crypt::encryptString($value) : null,
                'preview' => $secret ? $this->mask($value) : null,
                'fingerprint' => $secret ? $fingerprint : null,
                'created_by' => $existing?->created_by ?? $actorId,
                /* التدوير يُؤرَّخ: «متى تغيّر هذا المفتاح؟» سؤالٌ يُسأل بعد حادثة. */
                'rotated_at' => $existing === null ? null : $now,
                'revoked_at' => null,
            ];

            if ($existing === null) {
                $credential = IntegrationCredential::create($attributes);
            } else {
                $existing->update($attributes);
                $credential = $existing->fresh();
            }

            return $this->present($credential);
        });
    }

    /**
     * فكّ التعمية — للخادم وحده.
     *
     * لا تُنادى من وحدة تحكّم ولا تُسلسَل في استجابة. وهي الطريق الوحيد
     * لقراءة السرّ، فيبقى موضعُ استعماله واحدًا ومعروفًا.
     */
    public function reveal(string $provider, string $environment, string $field): ?string
    {
        $credential = IntegrationCredential::query()
            ->where('provider', $provider)
            ->where('environment', $environment)
            ->where('field', $field)
            ->whereNull('revoked_at')
            ->first();

        if ($credential === null) {
            return null;
        }

        /* «آخر استعمال» يجيب: أيّ المفاتيح ميت فيُسحب بلا خوف؟ */
        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        if (! $credential->is_secret) {
            return $credential->value_public;
        }

        return Crypt::decryptString((string) $credential->value_encrypted);
    }

    /** يُبطل ولا يمحو: صفٌّ محذوف لا يجيب «من أبطله ومتى؟». */
    public function revoke(string $provider, string $environment, string $field): bool
    {
        return IntegrationCredential::query()
            ->where('provider', $provider)
            ->where('environment', $environment)
            ->where('field', $field)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                /*
                 * القيمة تُمحى عند السحب — والصفّ يبقى.
                 *
                 * الاحتفاظ بنصٍّ معمّى لمفتاحٍ أُبطل مخاطرةٌ بلا مقابل: لا أحد
                 * سيستعمله ثانيةً، ويبقى هدفًا. والبصمة تكفي للإجابة عن
                 * «هل كان هذا هو المفتاح المسرَّب؟».
                 */
                'value_encrypted' => null,
                'value_public' => null,
                'preview' => null,
            ]) > 0;
    }

    /**
     * ما يجوز عرضه — لكلّ مزوّد.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(?string $provider = null): array
    {
        return IntegrationCredential::query()
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->orderBy('provider')
            ->orderBy('environment')
            ->orderBy('field')
            ->get()
            ->map(fn (IntegrationCredential $c) => $this->present($c))
            ->all();
    }

    /**
     * التمثيل الآمن — لا سرّ فيه.
     *
     * @return array<string, mixed>
     */
    private function present(IntegrationCredential $credential): array
    {
        return [
            'provider' => $credential->provider,
            'environment' => $credential->environment,
            'field' => $credential->field,
            'isSecret' => (bool) $credential->is_secret,
            /* الظاهر كاملًا؛ والسرّ مقنَّعًا. */
            'value' => $credential->is_secret ? null : $credential->value_public,
            'preview' => $credential->preview,
            /*
             * ثمانيةُ محارف من البصمة: تكفي للمقارنة بما يعرضه المزوّد في
             * لوحته، ولا تكفي لعكس شيء.
             */
            'fingerprint' => $credential->fingerprint === null
                ? null
                : substr($credential->fingerprint, 0, 8),
            'revokedAt' => $credential->revoked_at?->toIso8601String(),
            'rotatedAt' => $credential->rotated_at?->toIso8601String(),
            'lastUsedAt' => $credential->last_used_at?->toIso8601String(),
            'updatedAt' => $credential->updated_at?->toIso8601String(),
        ];
    }

    /**
     * قناعٌ يُميّز ولا يُستعمل.
     *
     * البادئة تبقى لأنّها تقول أيّ مفتاحٍ هذا (`sk_live`)، وأربعة محارف من
     * الذيل تكفي لمطابقته بما في لوحة المزوّد. وما بينهما لا يُعرض.
     */
    private function mask(string $value): string
    {
        $tail = mb_substr($value, -4);
        $head = '';

        foreach (['sk_live_', 'sk_test_', 'pk_live_', 'pk_test_', 'sk_', 'pk_'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $head = rtrim($prefix, '_');

                break;
            }
        }

        return ($head === '' ? '' : $head.'_').'••••'.$tail;
    }

    private function assertKnown(string $provider, string $environment, string $field): void
    {
        if (! in_array($environment, IntegrationCatalogue::ENVIRONMENTS, true)) {
            throw ValidationException::withMessages(['environment' => ['بيئة غير معروفة.']]);
        }

        if (! IntegrationCatalogue::knows($provider, $field)) {
            /*
             * حقلٌ حرّ الاسم يعني أنّ الخادم لا يعرف أيّ قيمةٍ سرٌّ — فيُخزَّن
             * `sk_live_…` ظاهرًا لأنّ من أدخله سمّاه «ملاحظة».
             */
            throw ValidationException::withMessages([
                'field' => ['حقلٌ غير معروف لهذا المزوّد — المجموعة مغلقة عمدًا.'],
            ]);
        }
    }

    private function assertEnvironmentMatches(string $environment, string $value): void
    {
        $declared = str_contains($value, '_live_') ? 'live' : (str_contains($value, '_test_') ? 'test' : null);

        if ($declared !== null && $declared !== $environment) {
            throw ValidationException::withMessages([
                'value' => ["المفتاح يعلن بيئة «{$declared}» وأنت تحفظه في «{$environment}» — مفتاح الإنتاج في بيئة تجربة يُحصّل مالًا حقيقيًّا."],
            ]);
        }
    }
}

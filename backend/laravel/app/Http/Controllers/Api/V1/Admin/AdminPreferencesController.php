<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminPreferences;
use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تفضيلات المشغّل الحاليّ — `GET/PUT /api/v1/admin/me/preferences`.
 *
 * ─── لصاحبها وحده ───
 *
 * لا معرّفَ في المسار: الحساب هو من فتح الجلسة (`AdminAuthenticate` يربطه).
 * فلا صلاحيةَ تُطلب، ولا طريقَ لقراءة تفضيل زميلٍ أو كتابته — السؤال «من
 * يملك هذه الوثيقة؟» جوابُه الجلسة لا الجسم.
 *
 * ─── الكتابة بالقسم، والتعارض صريح ───
 *
 * الجسم يحمل ما تغيّر من الأقسام (`appearance`، `dashboard`) ويستبدل كلّ قسمٍ
 * أُرسل كاملًا ويترك غيره: شريحة المظهر وشريحة اللوحة تكتبان في وثيقةٍ واحدة
 * دون أن تمحو إحداهما الأخرى. و`version` — إن أُرسل — يجب أن يطابق المحفوظ،
 * وإلّا ردّ ٤٠٩ بالوثيقة الحاليّة لتُعاد الكتابة فوقها لا فوق نسخةٍ قديمة.
 * وبلا `version` تُقبل الكتابة كما هي (آخرُ كاتبٍ يغلب) — لمن لا يتتبّع النسخ.
 */
class AdminPreferencesController extends Controller
{
    public function show(AdminUser $user): JsonResponse
    {
        return response()->json($this->payload($user));
    }

    public function update(Request $request, AdminUser $user): JsonResponse
    {
        if (strlen($request->getContent()) > AdminPreferences::MAX_BYTES) {
            return response()->json([
                'message' => 'وثيقة التفضيلات أكبر من الحدّ المسموح ('.AdminPreferences::MAX_BYTES.' بايت).',
                'code' => 'PREFERENCES_TOO_LARGE',
            ], 413);
        }

        $body = $request->json()->all();

        $version = $body['version'] ?? null;
        if ($version !== null && (! is_int($version) || $version < 0)) {
            throw ValidationException::withMessages(['version' => ['يجب أن يكون رقم النسخة عددًا صحيحًا.']]);
        }

        /* التنقية قبل القفل: لا معاملةَ تُفتح لجسمٍ سيُرفض. */
        $changes = [];
        foreach (AdminPreferences::sections() as $name) {
            if (array_key_exists($name, $body)) {
                $changes[$name] = AdminPreferences::section($name, $body[$name]);
            }
        }

        $conflict = null;

        DB::transaction(function () use ($user, $version, $changes, &$conflict): void {
            /** @var AdminUser $fresh */
            $fresh = AdminUser::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($version !== null && $version !== (int) $fresh->preferences_version) {
                $conflict = $fresh;

                return;
            }

            if ($changes === []) {
                $user->setRawAttributes($fresh->getAttributes(), true);

                return;
            }

            $document = is_array($fresh->preferences) ? $fresh->preferences : [];
            foreach ($changes as $name => $section) {
                if ($section === null) {
                    unset($document[$name]);
                } else {
                    $document[$name] = $section;
                }
            }

            $fresh->forceFill([
                'preferences' => $document,
                'preferences_version' => (int) $fresh->preferences_version + 1,
            ])->save();

            $user->setRawAttributes($fresh->getAttributes(), true);
        });

        if ($conflict !== null) {
            return response()->json([
                'message' => 'تغيّرت التفضيلات من نافذةٍ أخرى — أُعيدت النسخة الحاليّة.',
                'code' => 'PREFERENCES_CONFLICT',
                ...$this->payload($conflict),
            ], 409);
        }

        return response()->json($this->payload($user));
    }

    /**
     * `data` هي الوثيقة نفسها (عقد §3) و`meta` ما عنها — فمن يقرأ `data`
     * وحدها (شريحة المظهر) يجد الأقسام مباشرةً، ولا يخلط رقمَ النسخة بقسم.
     *
     * @return array{data: object, meta: array{version: int, updatedAt: string|null}}
     */
    private function payload(AdminUser $user): array
    {
        $document = is_array($user->preferences) ? $user->preferences : [];

        return [
            'data' => self::objects($document),
            'meta' => [
                'version' => (int) $user->preferences_version,
                'updatedAt' => $user->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * المصفوفة الفارغة تُرمَّز `[]` في JSON، والعقد كائنات: `widgets: {}` لا
     * `widgets: []`. التحويل عودِيٌّ لأنّ الخرائط متداخلة (widgets، canvas).
     */
    private static function objects(array $value): object
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_array($item) ? self::objects($item) : $item;
        }

        return (object) $out;
    }
}

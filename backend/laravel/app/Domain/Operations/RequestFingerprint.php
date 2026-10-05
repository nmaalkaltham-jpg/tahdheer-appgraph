<?php

namespace App\Domain\Operations;

/**
 * بصمةٌ مستقرّة لمعاملات الطلب المؤثّرة.
 *
 * ─── لماذا تُرتَّب المفاتيح ───
 *
 * ترتيب الحقول في JSON ليس جزءًا من معناه. عميلٌ يرسل الحقول بترتيبٍ آخر
 * يرسل **الطلب نفسه**، وبصمةٌ تتبع الترتيب كانت ستُعلنه طلبًا مختلفًا
 * فتردّ ٤٠٩ على إعادة إرسالٍ مشروعة — وهو عطلٌ أسوأ من الذي تمنعه.
 *
 * ─── ما يُستبعد ───
 *
 * مفتاح الخمول نفسه لا يدخل البصمة: هو الهويّة لا المحتوى. وإدخالُه يجعل
 * كلّ بصمةٍ فريدةً بالضرورة فيبطل الفحص كلّه.
 */
class RequestFingerprint
{
    /** @param array<string, mixed> $payload */
    public static function of(array $payload): string
    {
        unset($payload['idempotencyKey'], $payload['idempotency_key']);

        return hash('sha256', (string) json_encode(
            self::normalise($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * ترتيبٌ عميق. القيم تبقى كما هي — التطبيع يمسّ الشكل لا المعنى.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function normalise($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalised = array_map(self::normalise(...), $value);

        // القائمة المرتّبة معنًى (ترتيب البنود)، والخريطة لا — فتُرتَّب وحدها.
        if (array_is_list($normalised)) {
            return $normalised;
        }

        ksort($normalised);

        return $normalised;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Models\Setting;
use InvalidArgumentException;

/**
 * كتابة محتوى الصفحة الرئيسة — الوجه الآخر لـ`HomeContent`.
 *
 * ─── لماذا كاتبٌ مستقلّ ولا قواعدَ تحقّقٍ بالنقاط ───
 *
 * شكل الحمولة يقرّره `HomeContent::blocks()` لا قواعدُ Laravel: كتابةُ
 * `hero.cta_secondary_label` و`why.*.description` قواعدَ نصّيّة تعني نسخةً
 * ثانية من السجلّ تنحرف عند أوّل حقلٍ يُضاف. الكاتب يقرأ السجلّ نفسه الذي
 * يقرأ منه القارئ، فما يُقبل هنا هو بالضبط ما يُصيَّر هناك.
 *
 * ─── والتطبيع يُسقط ولا يُصلح ───
 *
 * القارئ يُسقط ما ليس نصًّا وما كان فارغًا (`HomeContent::text`). والكاتب
 * يفعل الشيء نفسه **قبل** الحفظ، فلا يُخزَّن ما لن يُقرأ: مفتاحٌ محفوظ لا
 * يظهر في المتجر يجعل المحرّر يظنّ أنّه كتب شيئًا ولم يكتب.
 *
 * ─── والحذف فعلٌ صريح ───
 *
 * كتلةٌ تُرسَل فارغة تُحذف من `settings` ولا تُحفظ كائنًا فارغًا: صفٌّ موجود
 * بقيمةٍ فارغة و«لم يُضبط بعد» حالتان تُقرآن واحدةً في المتجر، فلا يُبقى
 * منهما إلّا واحدة.
 */
final class HomeContentWriter
{
    /**
     * يحفظ كتلةً ويُعيد ما صار مقروءًا فعلًا بعد الحفظ.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>|null القيمة المخزَّنة، أو null إن حُذفت
     *
     * @throws InvalidArgumentException مفتاحٌ خارج السجلّ
     */
    public static function save(string $key, array $payload): ?array
    {
        $spec = HomeContent::blocks()[$key] ?? null;
        if ($spec === null) {
            throw new InvalidArgumentException("مفتاحُ محتوًى غير معروف: {$key}");
        }

        $clean = match ($spec['kind']) {
            'block' => isset($spec['settings'])
                /* إعدادات الحركة: كائنٌ كامل بافتراضاته، والفراغ عودةٌ إليها (حذف). */
                ? ($payload === [] ? [] : HomeContent::sliderSettings($payload))
                : self::block($payload, $spec['fields'] ?? [], (bool) ($spec['trust'] ?? false), (bool) ($spec['title'] ?? true)),
            'cards' => self::cards($payload),
            'faq' => self::faq($payload),
            'coupon' => self::coupon($payload),
            'layout' => self::layout($payload),
            'slides' => self::slides($payload, HomeContent::payload($key) ?? []),
            default => throw new InvalidArgumentException("نوعُ كتلةٍ غير معروف: {$spec['kind']}"),
        };

        /* تصميم الهيرو يُطبَّع عند الحفظ بالقاعدة نفسها التي يُقرأ بها. */
        if ($key === HomeContent::HERO && is_array($clean) && array_key_exists('variant', $clean)) {
            $variant = HomeContent::heroVariant($clean['variant']);
            if ($variant === null) {
                unset($clean['variant']);
            } else {
                $clean['variant'] = $variant;
            }
        }

        if ($clean === [] || $clean === null) {
            Setting::query()->where('key', $key)->delete();

            return null;
        }

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $clean]);

        return $clean;
    }

    /**
     * كتلةُ عنوانٍ وحقول. بلا عنوان لا كتلة — وهو الشرط نفسه الذي يفرضه
     * القارئ (`HomeContent::block` يُعيد null بلا `title`)، فلا يُحفظ ما
     * سيُقرأ عدمًا.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function block(array $payload, array $fields, bool $withTrust, bool $requireTitle = true): array
    {
        $title = HomeContent::normalizeText($payload['title'] ?? null);
        if ($title === null && $requireTitle) {
            return [];
        }

        $out = [];
        foreach ($fields as $field) {
            $value = HomeContent::normalizeText($payload[$field] ?? null);
            if ($value !== null) {
                $out[$field] = $value;
            }
        }

        if ($withTrust) {
            $trust = self::strings($payload['trust'] ?? null);
            if ($trust !== []) {
                $out['trust'] = $trust;
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<array{title: string, description?: string}>
     */
    private static function cards(array $payload): array
    {
        $out = [];
        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = HomeContent::normalizeText($row['title'] ?? null);
            if ($title === null) {
                continue;
            }
            $card = ['title' => $title];
            $description = HomeContent::normalizeText($row['description'] ?? null);
            if ($description !== null) {
                $card['description'] = $description;
            }
            $out[] = $card;
        }

        return $out;
    }

    /**
     * سؤالٌ بلا جواب وعدٌ فارغ — يُسقط هنا كما يُسقط عند القراءة.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<array{question: string, answer: string}>
     */
    private static function faq(array $payload): array
    {
        $out = [];
        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }
            $question = HomeContent::normalizeText($row['question'] ?? null);
            $answer = HomeContent::normalizeText($row['answer'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }
            $out[] = ['question' => $question, 'answer' => $answer];
        }

        return $out;
    }

    /**
     * ترتيب الأقسام — قائمةُ مُعرِّفاتٍ معروفة، بلا تكرار، وبهيرو واحد.
     *
     * ─── ولماذا يُرفض هيروان ───
     *
     * `hero` و`smart-finder` كلاهما يحمل `h1`. وتفعيلُهما معًا يضع عنوانين
     * رئيسين في صفحةٍ واحدة — وهو خرقُ بنيةٍ يكسر ترتيب العناوين لقارئ
     * الشاشة ولمحرّك البحث، لا ذوقُ تصميم يُترك للمالك.
     *
     * والرفض استثناءٌ لا إسقاطٌ صامت: إسقاطُ أحدهما يعني أنّ المالك ضغط
     * «احفظ» فرأى ترتيبًا غير الذي اختاره، بلا ما يقول له لماذا.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     *
     * @throws InvalidArgumentException هيروان معًا
     */
    private static function layout(array $payload): array
    {
        $out = [];
        $heroes = [];

        foreach ($payload as $id) {
            $text = HomeContent::normalizeText($id);
            if ($text === null || ! isset(HomeContent::SECTIONS[$text]) || in_array($text, $out, true)) {
                continue;
            }
            $out[] = $text;
            if (HomeContent::SECTIONS[$text]['hero']) {
                $heroes[] = $text;
            }
        }

        if (count($heroes) > 1) {
            throw new InvalidArgumentException(
                'لا يمكن تفعيل أكثر من قسم هيرو واحد: '.implode(' و', $heroes)
                .' — كلاهما يحمل العنوان الرئيس، وصفحةٌ بعنوانين رئيسين تكسر ترتيب العناوين.'
            );
        }

        return $out;
    }

    /**
     * شرائح الهيرو — ثمانٍ على الأكثر، كلٌّ مطبَّعةٌ بقاعدة القارئ نفسها.
     *
     * ─── والمُعرِّف يولّده الخادم ويحفظه ───
     *
     * مُعرِّفٌ بالصيغة (`s_` + ست عشريّ) يُحفظ كما هو، فتبقى الشريحة هي نفسها
     * بعد إعادة الترتيب والتعديل. وما غاب أو تكرّر (شريحةٌ مُكرَّرة في اللوحة
     * تحمل مُعرِّف أصلها) يُولَّد له مُعرِّفٌ جديد لا يصطدم بغيره.
     *
     * والزائد عن الثماني يُقتطع لا يُرفض — «طبّع ولا ترفض»؛ واللوحة تمنع
     * الإضافة قبل الحدّ فلا يصل هذا الفرعَ إلّا عميلٌ آخر.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  array<array-key, mixed>  $stored
     * @return list<array<string, mixed>>
     */
    private static function slides(array $payload, array $stored): array
    {
        $taken = [];
        foreach ($stored as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $taken[$row['id']] = true;
            }
        }

        $out = [];
        $used = [];
        foreach ($payload as $row) {
            if (! is_array($row) || count($out) >= HomeContent::SLIDES_MAX) {
                continue;
            }
            $slide = HomeContent::normalizeSlide($row);
            if ($slide === null) {
                continue;
            }
            if ($slide['id'] === null || isset($used[$slide['id']])) {
                do {
                    $slide['id'] = 's_'.bin2hex(random_bytes(3));
                } while (isset($used[$slide['id']]) || isset($taken[$slide['id']]));
            }
            $used[$slide['id']] = true;
            $out[] = $slide;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, string>
     */
    private static function coupon(array $payload): array
    {
        $code = HomeContent::normalizeText($payload['code'] ?? null);

        return $code === null ? [] : ['code' => $code];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $text = HomeContent::normalizeText($item);
            if ($text !== null) {
                $out[] = $text;
            }
        }

        return $out;
    }
}

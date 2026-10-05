<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Exceptions\AiOutputInvalid;

/**
 * تحقّقٌ من خرج النموذج قبل أن يقترب من قاعدة البيانات.
 *
 * ─── لماذا مُتحقِّقٌ مكتوب لا مكتبة JSON Schema ───
 *
 * المطلوب مخطَّطٌ ضيّق: أنواعٌ أوّليّة وقوائم وكائنات وحقول مطلوبة. ومكتبةٌ
 * كاملة تضيف تبعيّةً إلى مشروعٍ فيه أربع تبعيّاتٍ إنتاجيّة، مقابل قواعد
 * تُكتب في مئة سطر. وميزانيّة التبعيّات في هذا المستودع مشدودة عمدًا.
 *
 * ─── واستخراج JSON من نصّ ───
 *
 * النموذج الصغير يحبّ أن يسبق JSON بمقدّمة أو يغلّفه بسياج شيفرة. القصّ هنا
 * متساهلٌ في **إيجاد** الكائن وصارمٌ في **قبوله**: نأخذ أوّل كائنٍ متوازن
 * ثمّ نطبّق المخطَّط كاملًا. التساهل في الإيجاد يرفع نسبة النجاح، والتساهل
 * في القبول يكتب بياناتٍ مخترعة.
 */
final class AiSchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws AiOutputInvalid
     */
    public function validate(string $raw, array $schema): array
    {
        $json = $this->extractObject($raw);

        if ($json === null) {
            throw new AiOutputInvalid('لا كائن JSON في خرج النموذج.', ['no_json'], $raw);
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AiOutputInvalid('خرج النموذج ليس JSON صالحًا: '.$e->getMessage(), ['malformed'], $raw);
        }

        if (! is_array($decoded)) {
            throw new AiOutputInvalid('خرج النموذج ليس كائنًا.', ['not_object'], $raw);
        }

        $problems = [];
        $clean = $this->apply($decoded, $schema, '', $problems);

        if ($problems !== []) {
            throw new AiOutputInvalid('خرج النموذج خالف المخطَّط.', $problems, $raw);
        }

        return $clean;
    }

    /**
     * أوّل كائنٍ متوازن الأقواس في النصّ.
     *
     * العدّ يتجاهل الأقواس داخل السلاسل وما سبقه محرف هروب، وإلّا قطع عنوانًا
     * فيه `}` الكائنَ في منتصفه.
     */
    private function extractObject(string $raw): ?string
    {
        $start = strpos($raw, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * تطبيق المخطَّط: يُبقي المعلَن ويُسقط الزائد.
     *
     * إسقاط الحقل غير المُعلَن مقصود: نموذجٌ يضيف `"note": "..."` لا يجب أن
     * يُكتب في جدولٍ لا عمود له، ووجوده في الخرج ليس خطأً يستحقّ الرفض.
     *
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function apply(array $value, array $schema, string $path, array &$problems): array
    {
        $out = [];
        $required = (array) ($schema['required'] ?? []);
        $properties = (array) ($schema['properties'] ?? []);

        foreach ($properties as $key => $rule) {
            $at = $path === '' ? $key : "{$path}.{$key}";
            $present = array_key_exists($key, $value);

            if (! $present) {
                if (in_array($key, $required, true)) {
                    $problems[] = "حقل مطلوب ناقص: {$at}";
                }

                continue;
            }

            /*
             * ─── الفراغ امتناعٌ لا مخالفة ───
             *
             * الموجّه يأمر النموذج صراحةً: «إن نقص ما يلزم فاترك الحقل فارغًا
             * ولا تملأه بالتخمين». وقيس ما حدث: امتثل النموذج فردّ
             * `"confidence": ""` و`"topic": ""`، فرفضه المُتحقِّق لأنّ الفراغ
             * ليس رقمًا — أي أنّنا كنّا نعاقب الامتناع الصادق ونقبل التخمين.
             *
             * فالفراغ في حقلٍ غير مطلوب يُعدّ غيابًا: يُسقَط بهدوء. وفي حقلٍ
             * مطلوب يبقى مخالفة، لأنّ المطلوب لا يُترك.
             */
            if ($this->isAbsent($value[$key]) && ! in_array($key, $required, true)) {
                continue;
            }

            $checked = $this->coerce($value[$key], (array) $rule, $at, $problems);

            if ($checked !== null) {
                $out[$key] = $checked;
            }
        }

        return $out;
    }

    /**
     * أهذه القيمة امتناعٌ لا قيمة؟
     *
     * النصّ الفارغ و`null` والقائمة الفارغة والكائن الفارغ كلّها «لا أعرف».
     * وكان الفحص على الأوّلين فقط، فمصفوفةٌ فارغة تمرّ وتُخزَّن اقتراحًا بلا
     * محتوى — يراه المشرف سطرًا فارغًا ويُعدّ في «ينتظر المراجعة».
     */
    private function isAbsent(mixed $value): bool
    {
        return $value === '' || $value === null || (is_array($value) && $value === []);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $problems
     */
    private function coerce(mixed $value, array $rule, string $path, array &$problems): mixed
    {
        $type = (string) ($rule['type'] ?? 'string');

        return match ($type) {
            'string' => $this->asString($value, $rule, $path, $problems),
            'number' => is_numeric($value) ? (float) $value : $this->reject($path, 'رقم', $problems),
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value))
                ? (int) $value
                : $this->reject($path, 'عدد صحيح', $problems),
            'boolean' => is_bool($value) ? $value : $this->reject($path, 'قيمة منطقيّة', $problems),
            'array' => $this->asArray($value, $rule, $path, $problems),
            'object' => is_array($value)
                ? $this->apply($value, $rule, $path, $problems)
                : $this->reject($path, 'كائن', $problems),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $problems
     */
    private function asString(mixed $value, array $rule, string $path, array &$problems): ?string
    {
        if (! is_string($value)) {
            return $this->reject($path, 'نصّ', $problems);
        }

        $text = trim($value);

        /*
         * القائمة المغلقة تُفرض ولا تُقرَّب: نموذجٌ يردّ «ثانوي» حيث المطلوب
         * `secondary` يُرفض. القبول التقريبيّ يكتب قيمةً لا يفهمها أحد.
         */
        if (isset($rule['enum']) && ! in_array($text, (array) $rule['enum'], true)) {
            $problems[] = "{$path}: قيمة خارج القائمة المسموحة.";

            return null;
        }

        $max = (int) ($rule['maxLength'] ?? 0);

        // القصّ لا الرفض: طولٌ زائد عيبُ إسهابٍ لا اختلاق.
        return $max > 0 ? mb_substr($text, 0, $max) : $text;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $problems
     * @return list<mixed>|null
     */
    private function asArray(mixed $value, array $rule, string $path, array &$problems): ?array
    {
        if (! is_array($value)) {
            return $this->reject($path, 'قائمة', $problems);
        }

        $items = (array) ($rule['items'] ?? ['type' => 'string']);
        $max = (int) ($rule['maxItems'] ?? 50);
        $out = [];

        foreach (array_values($value) as $index => $entry) {
            if (count($out) >= $max) {
                break;
            }

            $checked = $this->coerce($entry, $items, "{$path}[{$index}]", $problems);

            if ($checked !== null && $checked !== '') {
                $out[] = $checked;
            }
        }

        // التكرار يُزال: نموذجٌ يعيد الكلمة مرّتين لا يعني أنّها أهمّ.
        $unique = array_values(array_unique($out, SORT_REGULAR));

        // وقائمةٌ خلت بعد الترشيح امتناعٌ كذلك، لا قائمةٌ فارغة تُخزَّن.
        return $unique === [] ? null : $unique;
    }

    /** @param list<string> $problems */
    private function reject(string $path, string $expected, array &$problems): null
    {
        $problems[] = "{$path}: المتوقَّع {$expected}.";

        return null;
    }
}

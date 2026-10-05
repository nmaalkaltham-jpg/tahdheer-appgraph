<?php

declare(strict_types=1);

namespace App\Domain\Writing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * مزوّد المساعدة الكتابية عبر Anthropic.
 *
 * ما يفعله: يقترح صياغات عربية لحقل واحد، اعتمادًا على بيانات المنتج الحقيقية
 * وحدها. ما لا يفعله: لا يكتب في قاعدة البيانات، ولا يقرّر، ولا يخترع رقمًا أو
 * ادّعاءً — والتعليمات تمنعه صراحةً من ذلك، لأن «٣٠٠ شريحة» في وصف مُقترَح
 * تصير وعدًا تجاريًّا حين يضغط المشرف «تطبيق» دون أن يعدّه أحد.
 *
 * الفشل يُعلَن: مفتاح ناقص ⇒ `isConfigured() === false`، وخطأ شبكة أو ردّ غير
 * متوقّع ⇒ استثناء صريح. لا سلسلة احتياطية تُقدَّم بوصفها اقتراح نموذج.
 */
final class AnthropicWritingAssistant implements WritingAssistantProvider
{
    /** حدّ الاقتراحات — أكثر من خمسة يُغرق المراجعة بدل أن يعينها. */
    private const MAX = 5;

    /** ما يُطلب من النموذج لكل حقل: الغرض والحدّ. */
    private const FIELDS = [
        'title' => ['عنوان منتج تعليمي', 60],
        'shortDesc' => ['وصف مختصر لبطاقة المنتج', 160],
        'description' => ['وصف كامل لصفحة المنتج', 600],
        'seoTitle' => ['عنوان لنتائج البحث', 60],
        'seoDesc' => ['وصف لنتائج البحث', 155],
        'altText' => ['نصّ بديل لصورة الغلاف', 120],
        'faq' => ['سؤال شائع وجوابه', 300],
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function suggest(string $field, array $context, int $count = 3): array
    {
        if (! $this->isConfigured()) {
            throw WritingAssistantUnavailable::notConfigured($this->name());
        }

        $spec = self::FIELDS[$field] ?? null;
        if ($spec === null) {
            throw WritingAssistantUnavailable::providerFailed($this->name(), "حقل غير مدعوم: {$field}");
        }

        [$purpose, $limit] = $spec;
        $count = max(1, min($count, self::MAX));

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 1024,
                    'system' => $this->systemPrompt(),
                    'messages' => [[
                        'role' => 'user',
                        'content' => $this->userPrompt($purpose, $limit, $count, $context),
                    ]],
                ]);
        } catch (ConnectionException $e) {
            throw WritingAssistantUnavailable::providerFailed($this->name(), $e->getMessage());
        }

        if (! $response->successful()) {
            throw WritingAssistantUnavailable::providerFailed(
                $this->name(),
                'استجابة غير ناجحة: '.$response->status(),
            );
        }

        $text = $response->json('content.0.text');
        if (! is_string($text) || trim($text) === '') {
            // ردّ بلا نصّ ليس «صفر اقتراحات»؛ هو عطل، ويُقال عطلًا.
            throw WritingAssistantUnavailable::providerFailed($this->name(), 'ردّ بلا نصّ.');
        }

        return $this->parse($text, $count, $limit);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        أنت مساعد تحرير لمتجر سعودي يبيع تحاضير وملفّات تعليمية جاهزة للمعلّمين.

        قيود مُلزِمة:
        · اكتب بالعربية الفصحى المعاصرة، بنبرة مهنية موجزة بلا مبالغة.
        · لا تخترع أرقامًا (عدد الشرائح، الصفحات، الدروس) ولا تواريخ ولا أسماء.
          استخدم ما يصلك في السياق فقط، وإن لم يصلك رقم فلا تذكر رقمًا.
        · لا تَعِد بما لم يُذكر: لا «الأفضل» ولا «الأشمل» ولا ضمانات.
        · لا تذكر أسعارًا ولا عروضًا.
        · أعد الاقتراحات فقط: سطرًا لكل اقتراح، بلا ترقيم وبلا تعليق وبلا مقدّمة.
        PROMPT;
    }

    /** @param  array<string, mixed>  $context */
    private function userPrompt(string $purpose, int $limit, int $count, array $context): string
    {
        $known = [];
        foreach (['title' => 'العنوان', 'subtitle' => 'العنوان الفرعي', 'shortDesc' => 'الوصف المختصر', 'format' => 'الصيغة'] as $key => $label) {
            $value = $context[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $known[] = "{$label}: {$value}";
            }
        }

        // ما لا نعرفه يُقال، فلا يملأ النموذج الفراغ من عنده.
        $facts = $known === [] ? 'لا بيانات متاحة عن هذا المنتج.' : implode("\n", $known);

        return "اقترح {$count} صياغات مختلفة لـ: {$purpose}.\n"
            ."الحدّ الأقصى لكل صياغة {$limit} حرفًا.\n\n"
            ."بيانات المنتج المتاحة:\n{$facts}";
    }

    /** @return list<string> */
    private function parse(string $text, int $count, int $limit): array
    {
        $out = [];

        foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
            // ترقيم أو شرطة يسبق السطر رغم التعليمات — يُقصّ ولا يُرفض السطر.
            $clean = trim(preg_replace('/^\s*(?:[-·*]|\d+[.)])\s*/u', '', $line) ?? '');
            if ($clean === '') {
                continue;
            }

            $out[] = mb_substr($clean, 0, $limit);
            if (count($out) >= $count) {
                break;
            }
        }

        if ($out === []) {
            throw WritingAssistantUnavailable::providerFailed($this->name(), 'لم يُستخرج أي اقتراح من الردّ.');
        }

        return $out;
    }
}

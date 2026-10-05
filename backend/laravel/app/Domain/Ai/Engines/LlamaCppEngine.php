<?php

declare(strict_types=1);

namespace App\Domain\Ai\Engines;

use App\Domain\Ai\Contracts\AiEngine;
use App\Domain\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * محرّك llama.cpp — واجهة متوافقة مع OpenAI على شبكة Docker الداخليّة.
 *
 * ─── المهلتان ───
 *
 * `connectTimeout` قصيرةٌ عمدًا: خدمةٌ ساقطة يجب أن تُكتشف في ثانيتين لا في
 * ستّين، وإلّا حجز كلّ طلبٍ عاملَ PHP طوال المهلة الطويلة فسقط المتجر بسبب
 * الذكاء. و`timeout` طويلةٌ لأنّ التوليد على معالجٍ بلا مسرّع بطيء بطبعه.
 * النمط من `MeilisearchProvider` المجاور — نقطة اختناقٍ واحدة لا يفلت منها
 * نداء.
 *
 * ─── ولا `retry()` ───
 *
 * لا نداء في هذا المشروع يعيد المحاولة في طبقة HTTP. إعادةُ المحاولة عملُ
 * الطابور (`$tries` و`backoff()`)، وعميلٌ يعيد داخل طلبٍ يضاعف ميزانيّة
 * الزمن بلا أن يرى أحد.
 */
final class LlamaCppEngine implements AiEngine
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly float $connectTimeout,
    ) {}

    public function name(): string
    {
        return 'llama.cpp';
    }

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '';
    }

    public function isHealthy(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            /*
             * `/health` يردّ ٥٠٣ أثناء تحميل الأوزان و٢٠٠ بعده. المهلة هنا
             * مهلة الاتّصال لا مهلة التوليد: فحصٌ ينتظر دقيقةً ليس فحصًا.
             */
            return $this->http($this->connectTimeout)->get($this->baseUrl.'/health')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function complete(array $messages, float $temperature, int $maxTokens, float $timeout, bool $structured = false): array
    {
        if (! $this->isConfigured()) {
            throw AiUnavailable::notConfigured('AI_BASE_URL فارغ.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            /*
             * البثّ مُطفأ: البوّابة تعيد جوابًا كاملًا مُتحقَّقًا منه، ولا
             * معنى لبثّ نصٍّ قد يُرفض عند التحقّق بعد عرضه.
             */
            'stream' => false,
            /*
             * ─── إطفاء التفكير ليس تحسينًا، هو شرطُ عمل ───
             *
             * Qwen3.5 نموذج تفكير: يبثّ سلسلة استدلالٍ في `reasoning_content`
             * قبل الجواب. وقيس ذلك: طلبٌ بسقف ٣٠٠ رمزٍ استهلكها كلّها تفكيرًا
             * وعاد بـ`content` **فارغ** و`finish_reason: length`. أي أنّ كلّ
             * مهمّةٍ مُهيكَلة كانت ستفشل، لا تبطؤ فحسب.
             *
             * وبإطفائه هبط زمن التصنيف نفسه من ١١٫٣ ثانية إلى ٢٫٥.
             */
            'chat_template_kwargs' => ['enable_thinking' => false],
            /*
             * كسرُ الحلقة: النموذج الصغير يعلق مكرّرًا العبارة نفسها في قوائم
             * العربيّة حتّى ينفد السقف، فينقطع JSON ويُرفض خرجٌ كان سليمًا.
             */
            'frequency_penalty' => (float) config('ai.engine.frequency_penalty', 0.7),
            'presence_penalty' => (float) config('ai.engine.presence_penalty', 0.4),
        ];

        if ($structured) {
            /*
             * قيدُ نحوٍ لا رجاءٌ في الموجّه: النموذج ذو المليارَي وسيط يخالف
             * «اردد JSON فقط» كثيرًا — يسبقه بمقدّمة أو يغلّفه بسياج. والقيد
             * هنا يمنع توليد رمزٍ يكسر البنية أصلًا، فترتفع نسبة القبول ولا
             * يُهدَر استدلالٌ كامل ليُرفض عند التحقّق.
             */
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = $this->http($timeout)->post($this->baseUrl.'/v1/chat/completions', $payload);
        } catch (ConnectionException $e) {
            /*
             * انقطاعٌ قبل معرفة النتيجة. لا يُميَّز عن المهلة هنا لأنّ الأثر
             * واحد على المُستدعي: لا جواب. والتمييز يهمّ في المهامّ ذات الأثر
             * الخارجيّ، والاستدلال بلا أثر.
             */
            throw AiUnavailable::timeout($timeout);
        } catch (Throwable $e) {
            throw AiUnavailable::engineFailed($e->getMessage());
        }

        if (! $response->successful()) {
            throw AiUnavailable::engineFailed('الخدمة ردّت '.$response->status().'.');
        }

        $text = $response->json('choices.0.message.content');

        /*
         * ردٌّ ٢٠٠ بلا نصّ عطلٌ لا «صفر اقتراحات» — كما في
         * `AnthropicWritingAssistant`. تمريرُ الفراغ يجعل حقلًا يُمسح بصمت.
         */
        if (! is_string($text) || trim($text) === '') {
            throw AiUnavailable::engineFailed('ردّت الخدمة بلا نصّ.');
        }

        return [
            'text' => $text,
            'inputTokens' => (int) ($response->json('usage.prompt_tokens') ?? 0),
            'outputTokens' => (int) ($response->json('usage.completion_tokens') ?? 0),
        ];
    }

    /** نقطة الاختناق الوحيدة — لا نداء يفلت من المهلتين. */
    private function http(float $timeout): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout($this->connectTimeout);
    }
}

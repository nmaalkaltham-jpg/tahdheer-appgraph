<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * جواب البوّابة — نصٌّ أو بنيةٌ مُتحقَّقة، مع أثرٍ يُدقَّق.
 *
 * `data` غير فارغةٍ للمهامّ المُهيكَلة وحدها، و`text` للنصّيّة. ولا جواب
 * يخرج من البوّابة إلّا وقد مرّ بالتحقّق — ما فشل يرمي ولا يُعاد ناقصًا.
 */
final readonly class AiResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public AiTask $task,
        public string $promptId,
        public int $promptVersion,
        public string $model,
        public string $text,
        public array $data = [],
        public int $latencyMs = 0,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public bool $cached = false,
        /*
         * ثقةٌ يُعلنها النموذج لا تُحسب: لا معايرةَ لها، فتُمرَّر كما وردت
         * وتُقرأ إشارةً لا احتمالًا. وغيابها `null` لا صفرًا — صفرٌ يُقرأ
         * «واثقٌ أنّه خاطئ».
         */
        public ?float $confidence = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'task' => $this->task->value,
            'promptId' => $this->promptId,
            'promptVersion' => $this->promptVersion,
            'model' => $this->model,
            'text' => $this->text,
            'data' => $this->data,
            'latencyMs' => $this->latencyMs,
            'cached' => $this->cached,
            'confidence' => $this->confidence,
        ];
    }
}

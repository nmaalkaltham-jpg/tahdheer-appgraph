<?php

namespace App\Domain\Fulfillment\Executors;

/**
 * خريطة الاستراتيجيّة إلى منفّذها.
 *
 * تُبنى مرّةً عند الإنشاء لا عند كلّ بند: الطلب ذو المئة بند كان سيعيد
 * المسح مئة مرّة.
 *
 * وغيابُ منفّذٍ ليس خطأً بل حالةٌ معروفة: الحجز والتزويد لم يُنفَّذا بعد،
 * فوحداتُهما تبقى معلّقةً بسببٍ مكتوب. الاستثناء هنا كان سيُسقط طلبًا
 * سليمًا نصفُه رقميّ.
 */
class ExecutorRegistry
{
    /** @var array<string, FulfillmentExecutor> */
    private array $map = [];

    /** @param iterable<FulfillmentExecutor> $executors */
    public function __construct(iterable $executors)
    {
        foreach ($executors as $executor) {
            foreach ($executor->strategies() as $strategy) {
                $this->map[$strategy] = $executor;
            }
        }
    }

    public function for(string $strategy): ?FulfillmentExecutor
    {
        return $this->map[$strategy] ?? null;
    }

    /** @return list<string> */
    public function supported(): array
    {
        return array_keys($this->map);
    }
}

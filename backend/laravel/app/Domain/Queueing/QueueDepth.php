<?php

declare(strict_types=1);

namespace App\Domain\Queueing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * عمق الطابور — قارئٌ واحد لكلّ من يسأل.
 *
 * ─── لماذا صنفٌ مشترك ───
 *
 * السؤال نفسه كان يُجاب في ثلاثة مواضع بثلاث إجابات: شاشة النظام تقول «غير
 * مقيس» تحت Redis، ولوحة الذكاء تقيسه، وحارس الطابور في وحدة تحكّم الاقتراحات
 * يقيسه لسائقٍ واحد. فيرى المشغّل في شاشةٍ «لا أعلم» وفي جارتها رقمًا، ولا
 * يعرف أيّهما يصدق.
 *
 * ─── ولماذا Redis يُقاس ───
 *
 * الإنتاج يعمل بـRedis، وكان الجواب هناك «غير مقيس» دائمًا. أي أنّ العمق
 * يُخفى في البيئة الوحيدة التي يهمّ فيها. وLaravel يخزّن الطابور في قائمةٍ
 * باسم `queues:{name}` والمؤجَّل والمحجوز في مجموعتين مرتّبتين — والثلاث معًا
 * هي العمق الحقيقيّ.
 *
 * ─── وما لا يُقاس يُقال ───
 *
 * سائقٌ لا نعرف كيف نعدّه يردّ `null` لا صفرًا. صفرٌ هنا يُقرأ «لا شيء عالق»
 * وهو أسوأ جوابٍ ممكن حين يكون الطابور ممتلئًا ولا أحد يعلم.
 */
final class QueueDepth
{
    /**
     * الطوابير التي يستهلكها العامل فعلًا.
     *
     * تُقرأ من الضبط لا تُكتب هنا: قائمةٌ في الشيفرة وقائمةٌ في `--queue=`
     * تفترقان، فيصير طابورٌ يُقاس ولا يُستهلك أو يُستهلك ولا يُقاس.
     *
     * @return list<string>
     */
    public static function monitored(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('queue.monitored', 'default')),
        )));
    }

    /**
     * عمق طابورٍ واحد، أو `null` إن تعذّر القياس.
     */
    public static function of(string $queue): ?int
    {
        return match ((string) config('queue.default')) {
            'database' => Schema::hasTable('jobs')
                ? (int) DB::table('jobs')->where('queue', $queue)->count()
                : null,
            'redis' => self::redis($queue),
            default => null,
        };
    }

    /**
     * مجموع الطوابير المراقَبة، أو `null` إن تعذّر أيٌّ منها.
     *
     * الجمع الجزئيّ ممنوع: مجموعُ ثلاثةٍ من أربعةٍ يُعرض رقمًا أصغر من الحقيقة
     * ويبدو مقيسًا. إمّا الكلّ أو لا شيء.
     *
     * @return array{state: string, driver: string, pending: int|null, reason: string|null}
     */
    public static function summary(): array
    {
        $driver = (string) config('queue.default');
        $total = 0;

        foreach (self::monitored() as $queue) {
            $depth = self::of($queue);

            if ($depth === null) {
                return [
                    'state' => 'NOT_MEASURABLE',
                    'driver' => $driver,
                    'pending' => null,
                    'reason' => "سائق «{$driver}» لا يُعدّ، أو تعذّر الوصول إلى مخزنه.",
                ];
            }

            $total += $depth;
        }

        return ['state' => 'MEASURED', 'driver' => $driver, 'pending' => $total, 'reason' => null];
    }

    private static function redis(string $queue): ?int
    {
        try {
            $connection = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
            $key = 'queues:'.$queue;

            /*
             * الثلاثة معًا: الجاهز في قائمة، والمؤجَّل والمحجوز في مجموعتين
             * مرتّبتين. وقراءة القائمة وحدها تُظهر طابورًا فارغًا بينما مئة
             * مهمّةٍ تنتظر لحظتها.
             */
            return (int) $connection->llen($key)
                + (int) $connection->zcard($key.':delayed')
                + (int) $connection->zcard($key.':reserved');
        } catch (Throwable $e) {
            // «غير قابل للقياس» يُقال بسببه: صمتٌ هنا يُطفئ إنذار نموّ الطابور بلا أثر.
            Log::warning('queue.depth.unreadable', ['queue' => $queue, 'error' => $e->getMessage()]);

            return null;
        }
    }
}

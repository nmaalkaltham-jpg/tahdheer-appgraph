<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiEngine;
use Illuminate\Support\Facades\Cache;

/**
 * صحّة المحرّك — مقروءةً مرّةً كلّ بضع ثوانٍ لا مع كلّ طلب.
 *
 * ─── لماذا تُخزَّن ───
 *
 * فحصُ صحّةٍ قبل كلّ استدلال يضيف رحلة شبكةٍ إلى كلّ طلب، ويضاعف الحمل على
 * خدمةٍ مجهدةٍ أصلًا وقت الحاجة. والحالة لا تتغيّر في الثانية الواحدة.
 *
 * ─── ولماذا المدّة قصيرة ───
 *
 * خمس ثوانٍ: طويلةٌ بما يكفي لتوفير الرحلات، وقصيرةٌ بما يكفي لئلّا يبقى
 * القاطع يرفض دقيقةً بعد تعافي الخدمة.
 */
final class AiHealthService
{
    private const KEY = 'ai:health';

    private const TTL = 5;

    public function __construct(private readonly AiEngine $engine) {}

    public function isHealthy(): bool
    {
        /*
         * `remember` لا تخزّن `false` في كلّ السائقين بالشكل نفسه، فتُخزَّن
         * السلسلة صراحةً: حالة «ساقط» يجب أن تُخزَّن كما تُخزَّن «حيّ»، وإلّا
         * أُعيد فحص الخدمة الساقطة مع كلّ طلب — وهو الحمل الذي نتجنّبه.
         */
        $cached = Cache::get(self::KEY);

        if ($cached !== null) {
            return $cached === 'up';
        }

        $healthy = $this->engine->isHealthy();
        Cache::put(self::KEY, $healthy ? 'up' : 'down', self::TTL);

        return $healthy;
    }

    /** فحصٌ حيٌّ يتجاوز التخزين — لزرّ «افحص الآن» في لوحة التحكّم. */
    public function probe(): bool
    {
        $healthy = $this->engine->isHealthy();
        Cache::put(self::KEY, $healthy ? 'up' : 'down', self::TTL);

        return $healthy;
    }

    public function engineName(): string
    {
        return $this->engine->name();
    }

    public function isConfigured(): bool
    {
        return $this->engine->isConfigured();
    }
}

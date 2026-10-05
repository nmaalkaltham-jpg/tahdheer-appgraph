<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiEngine;
use App\Domain\Ai\Exceptions\AiUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * سياسة القبول — كلّ أسباب الرفض في موضعٍ واحد.
 *
 * ─── لماذا طبقةٌ مستقلّة ───
 *
 * الرايات وحدود التوازي وتحديد المعدّل والقاطع أربعة أشياء تُفحص قبل كلّ
 * استدلال. وتركُها في البوّابة يجعلها تُنسخ في كلّ مسارٍ جديد، وأوّل نسخةٍ
 * تنسى حدّ التوازي تفتح البابَ لتوازٍ غير محدود — وهو ممنوعٌ صراحةً.
 *
 * ─── الترتيب مقصود ───
 *
 * الأرخص أوّلًا: رايةٌ في الذاكرة قبل قاطعٍ في المخزن قبل فحص صحّةٍ عبر
 * الشبكة. فطلبٌ لميزةٍ مطفأة يُرَدّ بلا لمس الشبكة أصلًا.
 */
final class AiPolicy
{
    private const SLOT_KEY = 'ai:slots';

    public function __construct(
        private readonly AiEngine $engine,
        private readonly AiCircuitBreaker $breaker,
        private readonly AiHealthService $health,
    ) {}

    public function assertEnabled(): void
    {
        if (! (bool) config('ai.enabled')) {
            throw AiUnavailable::disabled();
        }

        /*
         * الإيقاف التشغيليّ يُقرأ مباشرةً لا عبر `AiControlService`: تلك
         * الخدمة تقرأ `activeSlots()` من هنا، فحقنُها يصنع دورةً في الحاوية.
         * المفتاح عقدٌ بين الاثنين، وهو أرخص من واجهةٍ ثالثة لقيمةٍ منطقيّة.
         */
        if (Cache::get('ai:paused') !== null) {
            throw AiUnavailable::disabled();
        }
    }

    public function assertFeature(string $feature): void
    {
        $this->assertEnabled();

        if (! (bool) config("ai.features.{$feature}")) {
            throw AiUnavailable::featureOff($feature);
        }
    }

    public function isFeatureOn(string $feature): bool
    {
        return (bool) config('ai.enabled') && (bool) config("ai.features.{$feature}");
    }

    /** يُنادى قبل كلّ استدلال. يرمي بالسبب الأوّل المانع. */
    public function assertMayInfer(?int $actorId): void
    {
        $this->assertEnabled();

        if (! $this->engine->isConfigured()) {
            throw AiUnavailable::notConfigured('لا عنوان لخدمة النموذج.');
        }

        if ($this->breaker->isOpen()) {
            throw AiUnavailable::circuitOpen($this->breaker->openSeconds());
        }

        /*
         * تحديد المعدّل بالفاعل لا بالمصدر: مشرفٌ واحد يضغط زرًّا خمسين مرّةً
         * لا يجب أن يمنع زميله. والعمل الخلفيّ بلا فاعلٍ يُحدّ باسمٍ واحد
         * مشترك — وهو المقصود: الطابور لا يُسابق نفسه.
         */
        $key = 'ai:actor:'.($actorId === null ? 'system' : (string) $actorId);
        $perMinute = (int) config('ai.limits.per_actor_per_minute');

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw AiUnavailable::rateLimited($perMinute);
        }

        /*
         * الفحص قبل الاستهلاك: كان `hit()` يسبق فحص الصحّة، فخدمةٌ ساقطة
         * تلتهم حصّة المشرف العشرين في الدقيقة بطلباتٍ لم تُنفَّذ — ثمّ يُمنع
         * من المحاولة حين تتعافى.
         */
        if (! $this->health->isHealthy()) {
            throw AiUnavailable::unhealthy();
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * حجز مقعدٍ من مقاعد التوازي.
     *
     * ─── لماذا لا `Cache::lock` وحده ───
     *
     * القفل واحدٌ أو لا شيء. والمطلوب عدّادٌ بسقف: مقعدٌ أو مقعدان حسب الضبط.
     * القفل هنا يحرس **تعديل العدّاد** لا الاستدلال نفسه، فالعدّاد يُقرأ
     * ويُكتب ذرّيًّا ولا يتسرّب مقعدٌ عند التزاحم.
     *
     * والمقعد له مهلة: عاملٌ يُقتل في منتصف استدلال كان سيترك مقعده محجوزًا
     * إلى الأبد فتتوقّف الميزة كلّها حتّى يتدخّل إنسان.
     */
    public function acquireSlot(): ?string
    {
        $max = max(1, (int) config('ai.limits.max_concurrent'));
        $ttl = (int) ceil((float) config('ai.engine.timeout')) + 30;

        $lock = Cache::lock(self::SLOT_KEY.':lock', 5);

        if (! $lock->get()) {
            return null;
        }

        try {
            $held = (array) Cache::get(self::SLOT_KEY, []);
            $now = time();

            // المقاعد المنتهية تُنظَّف عند كلّ محاولة — لا مهمّةَ كنسٍ منفصلة.
            $held = array_filter($held, static fn (int $expires): bool => $expires > $now);

            if (count($held) >= $max) {
                return null;
            }

            $token = bin2hex(random_bytes(8));
            $held[$token] = $now + $ttl;
            Cache::put(self::SLOT_KEY, $held, $ttl + 60);

            return $token;
        } finally {
            $lock->release();
        }
    }

    public function releaseSlot(string $token): void
    {
        $lock = Cache::lock(self::SLOT_KEY.':lock', 5);

        if (! $lock->get()) {
            /*
             * تعذّر القفل: المقعد يُترك لمهلته. تركُه أرخص من الانتظار —
             * وهو ينتهي وحده بعد المهلة، ولذلك كانت له مهلةٌ أصلًا.
             */
            return;
        }

        try {
            $held = (array) Cache::get(self::SLOT_KEY, []);
            unset($held[$token]);
            Cache::put(self::SLOT_KEY, $held, (int) config('ai.engine.timeout') + 90);
        } finally {
            $lock->release();
        }
    }

    public function activeSlots(): int
    {
        $now = time();

        return count(array_filter((array) Cache::get(self::SLOT_KEY, []), static fn (int $e): bool => $e > $now));
    }
}

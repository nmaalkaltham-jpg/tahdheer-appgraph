<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use Carbon\CarbonImmutable;

/**
 * مهل الاستجابة والحسم — مشتقّةٌ من الحدّة وحدها.
 *
 * ─── لماذا الحدّة ───
 *
 * مهلةٌ واحدة لكلّ القضايا تعني إمّا إنذارًا مستمرًّا على التافه وإمّا صمتًا
 * عن الحرج. والحدّة هي الفرق الوحيد المعروف وقت الفتح — النوع لا يفرّق
 * (حادثةٌ حرجة كاحتيالٍ حرج)، والموضوع لا يُعرف بعد.
 *
 * ─── لماذا تُحفظ لا تُحسب عند القراءة ───
 *
 * المهلة تُحسب مرّةً وتُخزَّن. وحسابُها عند كلّ قراءة يعني أنّ تعديل السياسة
 * غدًا يُعيد كتابة حكم أمس: قضيّةٌ خُرقت مهلتُها تصير سليمةً بأثرٍ رجعيّ،
 * وقياسُ الأداء يفقد معناه.
 */
final class CaseSla
{
    /**
     * دقائق: [أوّل استجابة، الحسم].
     *
     * `INFO` بلا مهلة: ليست دعوةً إلى فعل، وفرضُ مهلةٍ عليها يُنتج خرقًا
     * يوميًّا لا أحد يقصده.
     */
    private const TARGETS = [
        'CRITICAL' => [15, 240],
        'HIGH' => [60, 1440],
        'WARNING' => [480, 4320],
        'INFO' => [null, null],
    ];

    /**
     * @return array{firstResponse: ?CarbonImmutable, resolution: ?CarbonImmutable}
     */
    public static function dueFrom(string $severity, CarbonImmutable $openedAt): array
    {
        [$response, $resolution] = self::TARGETS[$severity] ?? self::TARGETS['WARNING'];

        return [
            'firstResponse' => $response === null ? null : $openedAt->addMinutes($response),
            'resolution' => $resolution === null ? null : $openedAt->addMinutes($resolution),
        ];
    }

    /** @return array<string, array{0: ?int, 1: ?int}> */
    public static function targets(): array
    {
        return self::TARGETS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\Exceptions\AiOutputInvalid;
use Illuminate\Console\Command;
use Throwable;

/**
 * قياسٌ على أوزانٍ حقيقيّة — لا يعمل في التكامل المستمرّ.
 *
 * ─── لماذا أمرٌ لا اختبار ───
 *
 * الاختبار يجب أن يكون حتميًّا وسريعًا، والنموذج ليس واحدًا منهما: جوابه
 * يتغيّر بين تشغيلين، وكلّ حالةٍ تكلف ثانية. فالحدود والسياسة تُختبر بنموذجٍ
 * مقلَّد في `AiGatewayTest`، والجودة تُقاس هنا على طلب.
 *
 * ─── ولا يُدّعى جاهزيّةٌ بلا رقم ───
 *
 * «يعمل جيّدًا» ليست نتيجة. هذا الأمر يطبع دقّةً ونسبة خرجٍ مرفوض ومئويّات
 * زمن، وهي ما يُبنى عليه قرار التشغيل.
 *
 *   php artisan ai:benchmark
 */
class AiBenchmarkCommand extends Command
{
    protected $signature = 'ai:benchmark {--runs=1 : كم مرّة تُعاد كلّ حالة}';

    protected $description = 'قياس دقّة التصنيف وزمنه على أوزانٍ حقيقيّة.';

    /**
     * مجموعة تقييمٍ صغيرة بمراجعَ بشريّة.
     *
     * الحالات مكتوبةٌ كما يكتبها التاجر فعلًا: بالاختصار وبلا تشكيل وبترتيبٍ
     * مختلف. ومجموعةٌ مصقولة تعطي رقمًا جميلًا لا يُشبه الإنتاج.
     *
     * @return list<array{title: string, expect: array<string, string>}>
     */
    private function cases(): array
    {
        return [
            ['title' => 'عرض بوربوينت علوم أول متوسط - الجهاز الهضمي', 'expect' => ['stage' => 'متوسط', 'resource_type' => 'عرض تقديمي']],
            ['title' => 'أوراق عمل رياضيات الصف الرابع الابتدائي الفصل الثاني', 'expect' => ['stage' => 'ابتدائي', 'resource_type' => 'أوراق عمل']],
            ['title' => 'اختبار نهائي كيمياء ثاني ثانوي', 'expect' => ['stage' => 'ثانوي', 'resource_type' => 'اختبار']],
            ['title' => 'تحضير درس لغتي الجميلة للصف الثالث الابتدائي', 'expect' => ['stage' => 'ابتدائي', 'resource_type' => 'تحضير']],
            ['title' => 'حلول تمارين الفيزياء ثالث ثانوي ف١', 'expect' => ['stage' => 'ثانوي', 'resource_type' => 'حلول']],
            ['title' => 'دليل المعلم للدراسات الاجتماعية سادس ابتدائي', 'expect' => ['stage' => 'ابتدائي', 'resource_type' => 'دليل معلم']],
            ['title' => 'بوربوينت انجليزي ثاني متوسط unit 3', 'expect' => ['stage' => 'متوسط', 'resource_type' => 'عرض تقديمي']],
            ['title' => 'اوراق عمل علوم ثالث متوسط الفصل الدراسي الاول', 'expect' => ['stage' => 'متوسط', 'resource_type' => 'أوراق عمل']],
        ];
    }

    public function handle(AiGateway $gateway): int
    {
        if (! (bool) config('ai.enabled')) {
            $this->error('AI_ENABLED مطفأة — لا قياس على محرّكٍ مطفأ.');

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $latencies = [];
        $fields = ['stage' => ['hit' => 0, 'seen' => 0], 'resource_type' => ['hit' => 0, 'seen' => 0]];
        $rejected = 0;
        $failed = 0;
        $total = 0;

        foreach ($this->cases() as $case) {
            for ($i = 0; $i < $runs; $i++) {
                $total++;

                try {
                    $response = $gateway->classify(new AiRequest(
                        task: AiTask::Classify,
                        promptId: 'product.classify',
                        variables: ['title' => $case['title'], 'description' => ''],
                        // بلا تخزين: القياس يقيس الاستدلال لا الذاكرة.
                        timeout: 60.0,
                    ));

                    $latencies[] = $response->latencyMs;

                    foreach ($case['expect'] as $field => $want) {
                        $got = $response->data[$field] ?? null;

                        if ($got !== null) {
                            $fields[$field]['seen']++;

                            if ($got === $want) {
                                $fields[$field]['hit']++;
                            }
                        }
                    }

                    $this->line(sprintf('  %5dms  %s', $response->latencyMs, json_encode($response->data, JSON_UNESCAPED_UNICODE)));
                } catch (AiOutputInvalid $e) {
                    $rejected++;
                    $this->warn('  رُفض الخرج: '.implode('، ', $e->problems));
                } catch (Throwable $e) {
                    $failed++;
                    $this->error('  فشل: '.$e->getMessage());
                }
            }
        }

        sort($latencies);
        $at = static fn (float $q): int => $latencies === [] ? 0 : $latencies[(int) floor($q * (count($latencies) - 1))];

        $this->newLine();
        $this->table(['المقياس', 'القيمة'], [
            ['الحالات', (string) $total],
            ['خرجٌ مرفوض', $rejected.($total === 0 ? '' : sprintf(' (%.1f%%)', $rejected / $total * 100))],
            ['أعطال', (string) $failed],
            /*
             * الدقّة على ما **أعلنه** النموذج: حقلٌ حذفه ليس خطأً في القيمة
             * بل امتناع. وخلطُهما يُنتج رقمًا لا يقول أيّ العلاجين يلزم —
             * موجّهٌ أوضح أم نموذجٌ أكبر.
             */
            ['دقّة المرحلة', $this->pct($fields['stage'])],
            ['تغطية المرحلة', sprintf('%d/%d', $fields['stage']['seen'], $total)],
            ['دقّة نوع المصدر', $this->pct($fields['resource_type'])],
            ['تغطية نوع المصدر', sprintf('%d/%d', $fields['resource_type']['seen'], $total)],
            ['زمن p50', $at(0.5).'ms'],
            ['زمن p95', $at(0.95).'ms'],
            ['أقصى زمن', ($latencies === [] ? 0 : end($latencies)).'ms'],
        ]);

        return self::SUCCESS;
    }

    /** @param array{hit: int, seen: int} $field */
    private function pct(array $field): string
    {
        // مقامٌ صفريّ لا نسبة له — كما في بقيّة المشروع.
        return $field['seen'] === 0 ? 'لا مقام' : sprintf('%.1f%% (%d/%d)', $field['hit'] / $field['seen'] * 100, $field['hit'], $field['seen']);
    }
}

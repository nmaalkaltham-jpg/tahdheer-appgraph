<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * توحيد نوع الطوابع الزمنيّة: بلا منطقة، كبقيّة المستودع.
 *
 * ─── العيب الذي اكتُشف بالتنفيذ ───
 *
 * أعمدةٌ من نوع `timestamptz` وُضعت في جداولٍ كلُّ ما حولها `timestamp`.
 * وكتابةُ `Carbon` عبر باني الاستعلام تُرسل نصًّا بلا إزاحة، فيفسّره
 * PostgreSQL بمنطقة الجلسة (UTC) لا بمنطقة التطبيق (+٣).
 *
 * والنتيجة قياسٌ خاطئ بثلاث ساعات: مهلةٌ انقضت قبل عشرين دقيقة قُرئت
 * **مستقبلًا** بساعتين وأربعين دقيقة، فلم يُرصد خرقٌ قطّ. أُثبت باختبار
 * أعطى فارقًا سالبًا مقداره ٩٦٠٠ ثانية لفارقٍ حقيقيّ مقداره ١٢٠٠.
 *
 * ─── لماذا التوحيد لا الإصلاح الموضعيّ ───
 *
 * ٣٥٤ عمودًا بلا منطقة مقابل ١٩ بها. والأقلّية هي الشاذّة، وخلطُ النوعين في
 * جدولٍ واحد يُنتج هذا الخطأ كلّما قُورن عمودٌ بـ`now()` في PHP — بصمتٍ،
 * لأنّ النصّ المعروض يبدو صحيحًا.
 *
 * ─── القيم القائمة ───
 *
 * `AT TIME ZONE` يحوّل بلا فقد: القيمة المخزَّنة لحظةٌ مطلقة، وتُقرأ بمنطقة
 * التطبيق فتصير الجدارَ الزمنيَّ نفسه الذي تقرؤه بقيّة الجداول.
 */
return new class extends Migration
{
    /** جدول ⇦ أعمدته التي أُنشئت `timestamptz` خطأً. */
    private const COLUMNS = [
        'cases' => ['opened_at', 'resolved_at', 'first_response_due_at', 'resolution_due_at',
            'first_responded_at', 'breached_at'],
        'case_events' => ['created_at'],
        'settlements' => ['settled_at'],
        'webhook_deliveries' => ['received_at', 'processed_at'],
        'operation_records' => ['lease_expires_at', 'heartbeat_at', 'reconciled_at'],
        'refunds' => ['notified_at'],
        'payments' => ['captured_at'],
        'studio_page_saves' => ['created_at'],
    ];

    public function up(): void
    {
        $this->convert('timestamp(0) without time zone');
    }

    public function down(): void
    {
        $this->convert('timestamp(0) with time zone');
    }

    private function convert(string $target): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $zone = (string) config('app.timezone', 'UTC');

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $current = DB::selectOne(
                    'select data_type from information_schema.columns where table_name = ? and column_name = ?',
                    [$table, $column],
                );

                if ($current === null) {
                    continue;
                }

                $isTz = str_contains((string) $current->data_type, 'with time zone');
                $wantTz = str_contains($target, 'with time zone');

                if ($isTz === $wantTz) {
                    continue;
                }

                // التحويل صريحٌ بالمنطقة: بدونه يقع الانزياح نفسه في الترحيل.
                DB::statement(sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s TYPE %s USING (%s AT TIME ZONE %s)',
                    $table, $column, $target, $column, DB::getPdo()->quote($zone),
                ));
            }
        }
    }
};

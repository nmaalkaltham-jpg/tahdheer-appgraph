<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تصليب سجلّ العمليّات: بصمة الطلب، وعقد الإيجار، والنتيجة المجهولة.
 *
 * ─── ثلاثة عيوب يصلحها هذا الجدول بعد توسعته ───
 *
 * ١ — **المفتاح وحده لا يكفي.** عميلٌ يُعيد استعمال مفتاحٍ بجسمٍ مختلف كان
 *     يتلقّى نتيجة العمليّة الأولى بوصفها نتيجته: يطلب نقل ٥ فيُقال له تمّ،
 *     والذي تمّ نقل ١٠ قبل دقيقة. البصمة تكشف الاختلاف فيُردّ ٤٠٩.
 *
 * ٢ — **العامل قد يموت.** عمليّةٌ تبقى «قيد التنفيذ» إلى الأبد تمنع إعادة
 *     المحاولة بلا أن ينجز أحدٌ شيئًا. عقد الإيجار بمهلةٍ ونبضٍ يجعل الموت
 *     مكتشَفًا: ينتهي الإيجار فيُلتقط السجلّ للتعافي.
 *
 * ٣ — **انتهاء المهلة ليس فشلًا.** النداء الذي لم يُجب قد يكون نجح عند
 *     المزوّد. تسجيلُه «فشلًا» ثمّ إعادته يُنفّذ الأثر مرّتين. الحالة
 *     `UNCERTAIN` تقول الحقيقة: لا نعرف — فلا يُعاد قبل التوفيق.
 */
return new class extends Migration
{
    private const STATES = ['RESERVED', 'PROCESSING', 'UNCERTAIN', 'SUCCEEDED', 'FAILED'];

    /** ما يقوله المزوّد عن الأثر حين نسأله بعد الشكّ. */
    private const PROVIDER_STATES = ['UNKNOWN', 'CONFIRMED', 'ABSENT'];

    public function up(): void
    {
        Schema::table('operation_records', function (Blueprint $table) {
            /*
             * بصمة الطلب: تجزئة المعاملات المؤثّرة وحدها بعد ترتيبها. تُقارن
             * عند كلّ إعادة استعمالٍ للمفتاح. قابلة للإفراغ لأنّ السجلّات
             * القائمة سبقت هذا العمود — والمقارنة تتخطّى الفارغ ولا تخترعه.
             */
            $table->string('request_fingerprint', 64)->nullable()->after('kind');

            /* عقد إيجارٍ: مالكٌ واحد، ومهلةٌ تنتهي، ونبضٌ يمدّها. */
            $table->string('lease_owner', 64)->nullable()->after('attempts');
            $table->timestampTz('lease_expires_at')->nullable()->after('lease_owner');
            $table->timestampTz('heartbeat_at')->nullable()->after('lease_expires_at');

            /* مرجع المزوّد: ما نسأل به عن الأثر حين نشكّ فيه. */
            $table->string('provider_reference', 191)->nullable()->after('failure_class');
            $table->string('provider_state', 16)->nullable()->after('provider_reference');
            $table->timestampTz('reconciled_at')->nullable()->after('completed_at');
            $table->unsignedSmallInteger('reconcile_attempts')->default(0)->after('reconciled_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // `enum` في Laravel قيدُ تحقّقٍ لا نوعٌ — يُستبدل ليقبل `UNCERTAIN`.
        DB::statement('ALTER TABLE operation_records DROP CONSTRAINT IF EXISTS operation_records_status_check');
        DB::statement('ALTER TABLE operation_records ADD CONSTRAINT operation_records_status_check CHECK (
            status IN ('."'".implode("','", self::STATES)."'".')
        )');

        DB::statement('ALTER TABLE operation_records ADD CONSTRAINT operation_records_provider_state_check CHECK (
            provider_state IS NULL OR provider_state IN ('."'".implode("','", self::PROVIDER_STATES)."'".')
        )');

        /*
         * الإيجار كلٌّ لا يتجزّأ: مالكٌ بلا مهلة يدوم إلى الأبد، ومهلةٌ بلا
         * مالك لا تُنسب إلى أحد. كلاهما يُفسد التعافي، فيمنعهما القيد.
         */
        DB::statement('ALTER TABLE operation_records ADD CONSTRAINT operation_records_lease_whole CHECK (
            (lease_owner IS NULL) = (lease_expires_at IS NULL)
        )');

        /* المجهول يجب أن يقول لماذا صار مجهولًا. */
        DB::statement("ALTER TABLE operation_records ADD CONSTRAINT operation_records_uncertain_explained CHECK (
            status <> 'UNCERTAIN' OR last_error IS NOT NULL
        )");

        /*
         * فهرسٌ جزئيّ: ماسح التعافي يقرأ الأحياء وحدهم. وهم قلّةٌ دائمًا،
         * فالفهرس الكامل على `status` كان سيمسح ملايين المنتهية بلا داعٍ.
         */
        DB::statement("CREATE INDEX operation_records_recoverable_idx
            ON operation_records (lease_expires_at)
            WHERE status IN ('PROCESSING', 'UNCERTAIN')");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS operation_records_recoverable_idx');

            foreach (['provider_state_check', 'lease_whole', 'uncertain_explained'] as $constraint) {
                DB::statement("ALTER TABLE operation_records DROP CONSTRAINT IF EXISTS operation_records_{$constraint}");
            }
        }

        Schema::table('operation_records', function (Blueprint $table) {
            $table->dropColumn([
                'request_fingerprint', 'lease_owner', 'lease_expires_at', 'heartbeat_at',
                'provider_reference', 'provider_state', 'reconciled_at', 'reconcile_attempts',
            ]);
        });
    }
};

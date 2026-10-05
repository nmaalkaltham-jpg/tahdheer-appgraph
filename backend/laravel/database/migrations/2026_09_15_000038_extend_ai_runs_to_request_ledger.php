<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع `ai_runs` ليصير دفتر نداءاتٍ كامل الدورة — لا جدولًا ثانيًا.
 *
 * ─── لماذا توسيعٌ لا إنشاء ───
 *
 * الواجهة تنادي `/admin/ai/requests` ولها نوعٌ مكتوب سلفًا (`AiRequestRow`)،
 * والخادم يملك `ai_runs` يحمل أكثر حقوله. وإنشاء `ai_requests` بجواره كان
 * يعني مصدرَي حقيقةٍ لنداءٍ واحد: أحدهما يعدّ في لوحة الاستهلاك والآخر في
 * الدفتر، فيختلفان بعد أوّل عطل.
 *
 * ─── ودورة حياةٍ حقيقيّة ───
 *
 * كان الصفّ يُكتب **بعد** انتهاء النداء، فلا وجود لحالةٍ «في الطابور» ولا
 * سبيل إلى إلغاء ما لم يبدأ. والإلغاء مطلوبٌ فعلًا: مهمّةٌ في طابورٍ مزدحم قد
 * تنتظر دقائق، ولا يملك المشغّل إيقافها إلّا بإطفاء الذكاء كلّه.
 *
 * فصارت الحالات ستًّا، والصفّ يُنشأ عند الجدولة ويُحدَّث عند التنفيذ.
 *
 * ─── والكلفة `null` لا صفر ───
 *
 * النموذج محلّيّ بلا سعرٍ مُعلَن للرمز. وصفرٌ هنا يُقرأ «مجّانيّ ومقيس»، وهو
 * ادّعاءٌ لا سند له: الكهرباء والعتاد كلفةٌ لا نعرف توزيعها على النداء.
 * `null` تقول «لا سعر مُعلَن» وهي الحقيقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->string('provider', 60)->default('local')->after('model');
            $table->string('field', 80)->nullable()->after('entity_id');
            $table->unsignedSmallInteger('attempts')->default(1)->after('cached');
            $table->unsignedBigInteger('retry_of_id')->nullable()->after('attempts');
            $table->integer('cost_minor')->nullable()->after('output_units');
            $table->timestamp('started_at')->nullable()->after('created_at');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->timestamp('cancelled_at')->nullable()->after('finished_at');
            $table->timestamp('updated_at')->nullable()->after('cancelled_at');

            $table->index('retry_of_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_status_check');
        DB::statement(
            'ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check '.
            "CHECK (status IN ('QUEUED','RUNNING','SUCCEEDED','FAILED','REJECTED','CANCELLED'))"
        );

        /*
         * الملغى يحمل وقت إلغائه، والمنتهي يحمل وقت انتهائه. حالةٌ نهائيّة بلا
         * وقتٍ تجعل «متى توقّف هذا» سؤالًا بلا جواب في التحقيق.
         */
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_cancelled_has_time CHECK (status <> 'CANCELLED' OR cancelled_at IS NOT NULL)");

        // الكلفة إمّا غير معلومة أو غير سالبة — لا مبلغ سالب لنداء.
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_cost_not_negative CHECK (cost_minor IS NULL OR cost_minor >= 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['ai_runs_cancelled_has_time', 'ai_runs_cost_not_negative'] as $constraint) {
                DB::statement("ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS {$constraint}");
            }

            DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_status_check');
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('SUCCEEDED','FAILED','REJECTED'))");
        }

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropIndex(['retry_of_id']);
            $table->dropColumn([
                'provider', 'field', 'attempts', 'retry_of_id', 'cost_minor',
                'started_at', 'finished_at', 'cancelled_at', 'updated_at',
            ]);
        });
    }
};

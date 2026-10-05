<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ العمليّات الخاملة التكرار (§٢١).
 *
 * ─── العيب الذي يصلحه ───
 *
 * «إعادة المحاولة» و«إعادة التحقّق» و«حركة المخزون» كلّها أفعالٌ تُستدعى
 * من زرّ. والزرّ يُنقر مرّتين، والمتصفّح يُعيد الإرسال، والشبكة تنقطع بعد
 * وصول الطلب وقبل وصول الجواب فيُعيد العميل المحاولة، والطابور يُعيد
 * تسليم الرسالة. أربعة مساراتٍ تؤدّي إلى تنفيذٍ مزدوج، وتعطيلُ الزرّ في
 * الواجهة لا يسدّ أيًّا منها.
 *
 * الخمول يحتاج حالةً **دائمة**: مفتاحٌ يُحجز في قاعدة البيانات قبل الفعل،
 * فيرى الطلب الثاني الحجزَ ويُعيد نتيجة الأوّل بدل أن ينفّذ ثانيةً.
 *
 * ─── لماذا تُخزَّن النتيجة ───
 *
 * الطلب المكرّر يجب أن يُعيد **ما أعاده الأوّل**. إرجاع «مكرّر» وحده يترك
 * العميل بلا جواب، فيُعيد المحاولة مرّةً ثالثة.
 *
 * ─── تصنيف الفشل (§١٠٥ ونظيره في المزوّدين) ───
 *
 * فشلُ اعتمادٍ ليس غيابَ ملفّ، وتجاوزُ حصّةٍ ليس عطلًا دائمًا. طيُّها في
 * «فشل» واحد يجعل إعادة المحاولة تُبنى على تخمين: يُعاد ما لن ينجح أبدًا،
 * ويُترك ما كان سينجح بعد ثانية.
 */
return new class extends Migration
{
    /** حالات العمليّة. */
    private const STATES = ['RESERVED', 'PROCESSING', 'SUCCEEDED', 'FAILED'];

    /** تصنيف الفشل — يقرّر هل يُعاد أم لا. */
    private const FAILURE_CLASSES = [
        'AUTHENTICATION', 'AUTHORIZATION', 'NOT_FOUND', 'RATE_LIMITED',
        'PROVIDER_UNAVAILABLE', 'TIMEOUT', 'INVALID_RESPONSE',
        'PERMANENT_BUSINESS', 'TEMPORARY', 'NOT_CONFIGURED',
    ];

    public function up(): void
    {
        Schema::create('operation_records', function (Blueprint $table) {
            $table->id();

            /*
             * المفتاح هو الهويّة. فريدٌ مطلقًا: هو ما يجعل الطلب الثاني
             * يصطدم بالأوّل بدل أن ينفّذ بجانبه.
             */
            $table->string('idempotency_key')->unique();
            $table->string('kind');                    // fulfillment_retry|asset_verify|stock_movement
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->enum('status', self::STATES)->default('RESERVED');
            $table->unsignedSmallInteger('attempts')->default(0);

            /* ما يُعاد للطلب المكرّر حرفيًّا. */
            $table->json('result')->nullable();

            $table->enum('failure_class', self::FAILURE_CLASSES)->nullable();
            $table->string('last_error', 1000)->nullable();

            /* يربط الطلب بالمهمّة بالسجلّ — يُقرأ حين يُسأل «ماذا جرى؟». */
            $table->string('correlation_id', 64)->nullable();
            $table->unsignedBigInteger('actor_admin_user_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'status']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('correlation_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            // ما انتهى له وقتُ انتهاء — وإلّا استحال قياس زمن التنفيذ.
            DB::statement("ALTER TABLE operation_records ADD CONSTRAINT operation_records_finished_has_time CHECK (
                status NOT IN ('SUCCEEDED', 'FAILED') OR completed_at IS NOT NULL
            )");

            /*
             * فشلٌ بلا تصنيفٍ ولا رسالة لا يُبنى عليه قرارُ إعادة. التصنيف
             * هو ما يفرّق بين «أعد بعد ثانية» و«لا تُعد أبدًا».
             */
            DB::statement("ALTER TABLE operation_records ADD CONSTRAINT operation_records_failure_classified CHECK (
                status <> 'FAILED' OR (failure_class IS NOT NULL AND last_error IS NOT NULL)
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قرار التمويل يُحفظ على التحصيل — لا يُستنتج من حالة الطلب.
 *
 *   FUNDED      هذا التحصيل موَّل طلبه.
 *   UNFUNDABLE  وصل المال ورُفض تمويله (مبلغ/عملة/تاجر/طلب/رسم مجهول) —
 *               `funding_reason` يقول لماذا. قابلٌ لإعادة التقييم بعد حلٍّ مشروع.
 *   EXTRA       تحصيلٌ ثانٍ على طلبٍ مموَّل سلفًا — مالٌ أُخذ مرّتين.
 *
 * ─── لماذا ───
 *
 * كان مفتاح الخمول (`provider:chargeId`) وحده يقرّر «عولج»: تحصيلٌ سُجِّل
 * مرفوضًا لا يُعاد تقييمه أبدًا، حتّى لو أُعيد فتح الطلب بحقّ — فيبقى المال
 * يتيمًا إلى الأبد. الآن «سُجِّل» غير «موَّل».
 *
 * و`intent_attempt` رقمُ محاولة الرسم للطلب: يدخل في `reference.idempotent`
 * عند Tap فتُعيد المحاولةُ بعد مهلةٍ الرسمَ نفسه، ولا يزيد إلّا بعد فشلٍ مؤكَّد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('funding', 16)->nullable()->after('state');
            $table->string('funding_reason', 48)->nullable()->after('funding');
            $table->unsignedSmallInteger('intent_attempt')->default(1)->after('pay_url');
            /*
             * دفترُ الاسترداد على التحصيل نفسه: ما رُدّ منه وما حُجز. يسمح بردّ
             * مالٍ لم يُموِّل طلبًا (رسمٌ مرفوض أو زائد) — ودفترُ الطلب لا يراه
             * لأنّ «المحصَّل» على الطلب صفر.
             */
            $table->integer('refunded_minor')->default(0)->after('amount_minor');
            $table->integer('refund_reserved_minor')->default(0)->after('refunded_minor');
        });

        Schema::table('refunds', function (Blueprint $table) {
            /* من أين جاء: التطبيق، أو لوحة المزوّد (اكتُشف بخطّافه)، أو إدخال قديم. */
            $table->string('source', 24)->default('app')->after('status');
            /*
             * `order`: يُردّ من مال الطلب الموَّل (دفترا الطلب والتحصيل معًا).
             * `capture`: يُردّ من تحصيلٍ لم يُموِّل الطلب (دفتر التحصيل وحده) —
             * لا يمسّ حالة الطلب ولا استحقاقه.
             */
            $table->string('scope', 16)->default('order')->after('source');
        });

        // ما رُدّ قائمًا يُنقل إلى دفتر تحصيله كما هو.
        DB::statement("UPDATE payments SET refunded_minor = COALESCE((
            SELECT SUM(r.amount_minor) FROM refunds r WHERE r.payment_id = payments.id AND r.status IN ('SUCCEEDED', 'MANUAL')
        ), 0)");
        DB::statement("UPDATE payments SET refund_reserved_minor = COALESCE((
            SELECT SUM(r.amount_minor) FROM refunds r WHERE r.payment_id = payments.id AND r.status IN ('PENDING', 'UNKNOWN')
        ), 0)");

        // التحصيلات القائمة: ما موّل طلبًا وصل مالُه FUNDED؛ وما سواه لا يُختلق له قرار.
        DB::statement("UPDATE payments SET funding = 'FUNDED' WHERE state = 'CAPTURED' AND order_id IN (
            SELECT id FROM orders WHERE status IN ('PAID','PROCESSING','COMPLETED','PARTIALLY_REFUNDED','REFUNDED')
        )");

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_funding_known CHECK (
            funding IS NULL OR funding IN ('FUNDED', 'UNFUNDABLE', 'EXTRA')
        )");
        // القرار للتحصيل وحده، والرفض يحمل سببه.
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_funding_only_when_captured CHECK (
            funding IS NULL OR state = 'CAPTURED'
        )");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_unfundable_has_reason CHECK (
            funding IS DISTINCT FROM 'UNFUNDABLE' OR funding_reason IS NOT NULL
        )");
        /*
         * طلبٌ واحد يموّله تحصيلٌ واحد. الثاني EXTRA بالتعريف — والمحرّك يرفض
         * تحصيلين «موِّلين» لطلبٍ واحد مهما كان المستدعي.
         */
        DB::statement("CREATE UNIQUE INDEX payments_one_funding_per_order ON payments (order_id) WHERE funding = 'FUNDED'");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_source_known CHECK (source IN ('app', 'provider', 'legacy'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_scope_known CHECK (scope IN ('order', 'capture'))");
        // ردٌّ من تحصيلٍ بعينه لا يُعرَف بلا تحصيله.
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_capture_scope_has_payment CHECK (scope <> 'capture' OR payment_id IS NOT NULL)");
        /*
         * المحرّك يحرس دفتر التحصيل كما يحرس دفتر الطلب: لا يُردّ من تحصيلٍ
         * أكثرُ ممّا قبضه المزوّد، محسومًا ومحجوزًا معًا.
         */
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refund_within_amount CHECK (
            refunded_minor >= 0 AND refund_reserved_minor >= 0
            AND (amount_minor IS NULL OR refunded_minor + refund_reserved_minor <= amount_minor)
        )');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payments_one_funding_per_order');
            foreach (['payments_funding_known', 'payments_funding_only_when_captured', 'payments_unfundable_has_reason'] as $c) {
                DB::statement("ALTER TABLE payments DROP CONSTRAINT IF EXISTS {$c}");
            }
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_source_known');
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_scope_known');
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_capture_scope_has_payment');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_refund_within_amount');
        }

        Schema::table('refunds', fn (Blueprint $t) => $t->dropColumn(['source', 'scope']));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['funding', 'funding_reason', 'intent_attempt', 'refunded_minor', 'refund_reserved_minor']));
    }
};

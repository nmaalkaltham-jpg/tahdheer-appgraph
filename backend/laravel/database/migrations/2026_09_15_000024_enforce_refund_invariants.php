<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ثابت ماليّ في المحرّك: المستردّ لا يتجاوز المحصَّل (§٧٠).
 *
 * ─── العيب ───
 *
 * كان السقف يُحسب في `OrderController` ثمّ يُمرَّر إلى التحقّق. وثلاث طرقٍ
 * تتجاوزه:
 *
 *   ١ — نداءٌ من خدمةٍ أو مهمّةٍ أو عمليّةٍ جماعيّة لا يمرّ بوحدة التحكّم.
 *   ٢ — طلبان متزامنان يقرآن المتبقّي نفسه قبل أن يكتب أحدهما.
 *   ٣ — إعادة إرسالٍ بعد ضياع الردّ تُنشئ استردادًا ثانيًا.
 *
 * والمال المستردّ زيادةً لا يُستعاد بزرّ: يخرج من الحساب فعلًا.
 *
 * ─── لماذا عمودٌ مجمَّع لا `CHECK` على الجمع ───
 *
 * قيد `CHECK` لا يرى صفوفًا أخرى. فالمجموع يُحفظ على الطلب ويُحدَّث في
 * المعاملة نفسها التي تُدرج الاسترداد، والقيد عليه — فيصير التجاوز
 * **مستحيلًا بنيويًّا** لا ممنوعًا بالاتّفاق.
 *
 * ─── حدّ المعرفة ───
 *
 * «المحصَّل» هنا هو `total_minor` للطلب المدفوع. والمبلغ المحصَّل الحقيقيّ
 * ليس محفوظًا على الدفعة أصلًا (`payments` بلا عمود مبلغ)، وهو نقصٌ مُعلَن:
 * تحصيلٌ جزئيّ أو بعملةٍ أخرى لا يُمثَّل اليوم. هذا القيد يُثبّت الافتراض
 * القائم بدل تركه ضمنيًّا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('refunded_total_minor')->default(0)->after('total_minor');
        });

        // المجموع القائم يُنقل كما هو — لا يُخترع ولا يُقرَّب.
        DB::statement('UPDATE orders SET refunded_total_minor = COALESCE((
            SELECT SUM(r.amount_minor) FROM refunds r WHERE r.order_id = orders.id
        ), 0)');

        Schema::table('refunds', function (Blueprint $table) {
            /*
             * مفتاح خمولٍ اختياريّ: الاستردادات القائمة بلا مفتاح، ومن
             * يُرسله يُحمى من إنشاء استردادٍ ثانٍ بردٍّ ضائع.
             */
            $table->string('idempotency_key', 120)->nullable()->after('revocation_policy');
            $table->unique(['order_id', 'idempotency_key']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_refund_within_captured CHECK (
            refunded_total_minor >= 0 AND refunded_total_minor <= total_minor
        )');

        // استردادٌ بصفرٍ أو بسالب ليس حركةً ماليّة — هو خطأُ إدخالٍ يُسجَّل.
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_refund_within_captured');
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_amount_positive');
        }

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('refunded_total_minor');
        });
    }
};

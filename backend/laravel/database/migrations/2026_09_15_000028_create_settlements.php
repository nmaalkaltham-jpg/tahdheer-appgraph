<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التسويات: ما دفعه المزوّد فعلًا بعد رسومه.
 *
 * ─── الحلقة الناقصة ───
 *
 * السلسلة الماليّة كانت تنتهي عند التحصيل: بوّابة ← دفعة ← طلب. وما بعده
 * مجهول تمامًا — كم وصل إلى الحساب البنكيّ، وكم اقتطع المزوّد رسومًا، وهل
 * وصل أصلًا.
 *
 * ونتيجةُ ذلك أنّ متجرًا يحصّل مئة ألفٍ شهريًّا لا يعرف كم منها صار مالًا
 * في يده. الرسوم تُقتطع بنسبةٍ متغيّرة، والتسوية تتأخّر أيّامًا، وتحصيلٌ
 * ناجح قد لا يُسوَّى أبدًا عند نزاع.
 *
 * ─── لماذا المبالغ الثلاثة ───
 *
 * `gross` ما حُصِّل، و`fee` ما اقتُطع، و`net` ما وصل. وحفظُ اثنين واشتقاقُ
 * الثالث يبدو اقتصادًا وهو خطأ: المزوّد قد يُبلّغ بثلاثةٍ لا تتطابق —
 * وذلك بعينه ما يجب أن يُكشف لا أن يُخفى بحساب.
 *
 * ─── لماذا لا تُختلق ───
 *
 * لا مصدر تسوياتٍ مربوط اليوم. والجدول يبقى فارغًا حتّى تُستورَد بيانات
 * حقيقيّة — ولا يُملأ بتقديرٍ من الطلبات. تسويةٌ مخترعة تجعل التدقيق يوافق
 * على أرقامٍ لم يرها بنك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);

            /* مرجع المزوّد للدفعة الواحدة داخل التسوية. */
            $table->string('charge_ref', 191);
            /* مرجع دفعة التسوية نفسها (batch/payout). */
            $table->string('settlement_ref', 191);

            $table->unsignedBigInteger('payment_id')->nullable();

            $table->integer('gross_minor');
            $table->integer('fee_minor');
            $table->integer('net_minor');
            $table->string('currency', 3);

            $table->timestampTz('settled_at');

            /*
             * مصدر الصفّ: مستورَدٌ من المزوّد أم مُدخَل يدويًّا. التدقيق
             * يحتاج التفرقة — رقمٌ كتبه إنسان ليس رقمًا أرسله بنك.
             */
            $table->string('source', 16)->default('PROVIDER');

            $table->json('raw')->nullable();
            $table->timestamps();

            // لا تسوية مزدوجة للرسم نفسه في الدفعة نفسها.
            $table->unique(['provider', 'settlement_ref', 'charge_ref']);
            $table->index('payment_id');
            $table->index('settled_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * الصافي = الإجماليّ − الرسوم. قيدٌ في المحرّك لا حسابٌ في الشيفرة:
         * صفٌّ لا يتّسق داخليًّا يجب أن يُرفض عند الاستيراد لا أن يُكتشف
         * بعد شهرٍ في تقرير.
         */
        DB::statement('ALTER TABLE settlements ADD CONSTRAINT settlements_net_is_gross_minus_fee CHECK (
            net_minor = gross_minor - fee_minor
        )');

        // الرسوم لا تكون سالبة، والإجماليّ لا يكون صفرًا.
        DB::statement('ALTER TABLE settlements ADD CONSTRAINT settlements_amounts_sane CHECK (
            gross_minor > 0 AND fee_minor >= 0
        )');

        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_source_check CHECK (
            source IN ('PROVIDER', 'MANUAL')
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};

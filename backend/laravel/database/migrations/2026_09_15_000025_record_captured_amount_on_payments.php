<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المبلغ المحصَّل يُحفظ على الدفعة (§١٦ §١٧).
 *
 * ─── العيب ───
 *
 * `payments` كان بلا عمود مبلغ. أي أنّ الحقيقة الماليّة الوحيدة في النظام
 * هي `orders.total_minor` — وهو **ما طُلب** لا **ما وصل**. والمزوّد يُبلّغ
 * المبلغ والعملة في الخطّاف، ويوقّعهما (Tap يُدخل `x_amount` و`x_currency`
 * في التوقيع)، فكانت حقيقةٌ موثّقة تُقرأ ثمّ تُرمى.
 *
 * ونتيجةُ ذلك أنّ التسوية (§١٧) مستحيلة بنيويًّا: لا يمكن كشف «فرقٌ في
 * المبلغ» ولا «عملةٌ مختلفة» ولا «تحصيلٌ ناقص» حين لا يُحفظ إلّا طرفٌ واحد
 * من المقارنة.
 *
 * ─── لماذا لا يُملأ بأثرٍ رجعيّ ───
 *
 * الدفعات القائمة لا نعرف ما أبلغ به المزوّد عنها. ونسخُ `total_minor`
 * إليها يخلق **مطابقةً مصطنعة**: تسويةٌ تقول «كلّ شيء متطابق» لأنّها تقارن
 * الرقم بنفسه. `NULL` تعني «غير معروف» — وهي الحقيقة، وتُعدّ وتُعرض.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            /* ما أبلغ به المزوّد. `NULL` = لم يُبلّغ أو لم يُسجَّل بعد. */
            $table->integer('amount_minor')->nullable()->after('state');
            $table->string('currency', 3)->nullable()->after('amount_minor');
            $table->timestampTz('captured_at')->nullable()->after('currency');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // مبلغٌ بسالب ليس تحصيلًا. والصفر ليس تحصيلًا أيضًا.
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (
            amount_minor IS NULL OR amount_minor > 0
        )');

        /*
         * مبلغٌ بلا عملة لا يُقارَن بشيء: ١٠٠٠٠ ريالٍ و١٠٠٠٠ دولار رقمان
         * متطابقان ومبلغان مختلفان. يُفرض اقترانهما.
         */
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_has_currency CHECK (
            (amount_minor IS NULL) = (currency IS NULL)
        )');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_positive');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_has_currency');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['amount_minor', 'currency', 'captured_at']);
        });
    }
};

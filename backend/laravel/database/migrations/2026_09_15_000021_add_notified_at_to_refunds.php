<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `refunds.notified_at` — عمودٌ كان في التطوير بلا هجرة.
 *
 * ─── كيف اكتُشف ───
 *
 * اختبارٌ يقرأ العمود فشل على قاعدة الاختبار وحدها: هي تُبنى بالهجرات،
 * وقاعدة التطوير تراكمت بتعديلاتٍ يدويّة. فكان العمود موجودًا حيث يُنظر
 * إليه وغائبًا حيث يُبنى.
 *
 * ─── لماذا هذا خطير ───
 *
 * نشرٌ نظيف كان سيُنتج قاعدةً بلا هذا العمود، فينهار كلّ ما يقرؤه — في
 * الإنتاج، وفي أوّل طلبٍ يلمسه، وبعد أن تُعلَن الترقية ناجحة.
 *
 * ─── معناه ───
 *
 * متى أُبلغ العميل باسترداده. و`null` تعني «لم يُبلَّغ بعد» لا «لم يُسترد»:
 * المال عاد ولا يعلم، فيشكو «أين مالي؟» عن مشكلةٍ حُلّت سلفًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('refunds', 'notified_at')) {
            // قاعدة التطوير تحمله سلفًا — الهجرة تُسوّي ولا تُكرّر.
            return;
        }

        Schema::table('refunds', function (Blueprint $table) {
            $table->timestampTz('notified_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};

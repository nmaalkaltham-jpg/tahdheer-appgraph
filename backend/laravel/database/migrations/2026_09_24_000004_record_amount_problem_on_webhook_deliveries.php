<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سببُ عدم قراءة المبلغ يُحفظ مع التسليم.
 *
 * مبلغٌ مفقود أو بمنازل زائدة أو صفرٌ في حمولةٍ موقّعة ليس «غير معروف»
 * يُموِّل — هو فرقٌ يُرفض تمويله. ولأنّ إعادة التشغيل تبني الحدث من الصفّ
 * لا من الحمولة، فالسبب يُحفظ هنا وإلّا عادت الإعادة فموّلت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->string('amount_problem', 32)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn('amount_problem');
        });
    }
};

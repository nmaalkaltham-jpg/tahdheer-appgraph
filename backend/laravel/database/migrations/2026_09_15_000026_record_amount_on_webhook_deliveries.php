<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المبلغ المُبلَّغ يُحفظ على التسليم الوارد.
 *
 * إعادة التشغيل تُعيد بناء الحدث من هذا الصفّ لا من تحليل الحمولة: حدثٌ
 * وصل قبل سنة يجب أن يُفهم بقواعد يومه لا بقواعد اليوم. والحقول المعنويّة
 * تُحفظ أعمدةً لهذا السبب — وقد حُفظ `succeeded` كذلك سلفًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->integer('amount_minor')->nullable()->after('succeeded');
            $table->string('currency', 3)->nullable()->after('amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn(['amount_minor', 'currency']);
        });
    }
};

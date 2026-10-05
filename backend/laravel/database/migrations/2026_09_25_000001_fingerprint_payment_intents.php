<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بصمة الطلب المالي الذي أُنشئت له النيّة: المبلغ والعملة والطلب والطريقة.
 *
 * مفتاح Tap (`reference.idempotent`) يعيد الرسمَ الأوّل لأيّ جسمٍ يحمل المفتاح
 * نفسه — والوثائق لا تقول إنّه يرفض جسمًا مختلفًا. فالمفتاح نفسه بمبلغٍ آخر
 * كان سيعيد رسمًا بالمبلغ القديم لطلبٍ يتوقّع غيره. البصمة تجعل هذا خطأً
 * صريحًا عندنا قبل أيّ نداء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('intent_fingerprint', 64)->nullable()->after('intent_attempt');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('intent_fingerprint'));
    }
};

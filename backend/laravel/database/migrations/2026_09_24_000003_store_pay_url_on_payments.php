<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رابط الدفع يُحفظ على صفّ النيّة.
 *
 * كان يُعاد للمتصفّح مرّةً ويُرمى: من أغلق الصفحة قبل الدفع، أو فشل إنشاء
 * النيّة عنده، بقي طلبُه «بانتظار الدفع» بلا طريقٍ إليه — طلبٌ ميت. الحفظ
 * يجعل «أعد لي رابط الدفع» سؤالًا له جواب بلا نيّةٍ ثانية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('pay_url', 2048)->nullable()->after('intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('pay_url');
        });
    }
};

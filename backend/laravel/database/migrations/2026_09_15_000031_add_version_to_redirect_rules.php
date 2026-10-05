<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نسخةُ قاعدة التحويل — لتنتهي «آخر كاتبٍ يفوز».
 *
 * ─── العيب ───
 *
 * كان الحفظ يقرأ الصفّ ثمّ يكتب فوقه بلا قفلٍ ولا مقارنة. مشغّلان يفتحان
 * القاعدة نفسها، يُصحّح الأوّل الهدف ويُصحّح الثاني الرمز — فيختفي تصحيح
 * الأوّل بلا أثرٍ ولا رسالة. والتحويل ليس حقلًا تجميليًّا: هدفٌ خاطئ يرسل
 * زوّار صفحةٍ مُفهرَسة إلى مكانٍ لا يقصده أحد، ولا يُكتشف إلّا بهبوط
 * الزيارات بعد أسابيع.
 *
 * العدّاد `hits` عمدًا **خارج** النسخة: إصابةُ زائرٍ ليست تحريرًا، ورفعُها
 * النسخةَ كان سيجعل كلّ نموذجٍ مفتوحٍ بائتًا بمجرّد مرور أوّل زائر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redirect_rules', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('redirect_rules', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};

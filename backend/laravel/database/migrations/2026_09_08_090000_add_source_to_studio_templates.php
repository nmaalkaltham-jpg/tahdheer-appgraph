<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منشأ القالب (عقد المرحلة ٣٫٥، قرار C48): `page` من مسوّدة صفحة كاملة —
 * وهو كل ما كان ممكنًا حتى اليوم — أو `node` من عقدة واحدة في متصفّح العناصر.
 *
 * العمود موجود ليقول الحقيقة في القائمة لا لتغيير سلوك: قالبُ عقدةٍ وقالبُ
 * صفحةٍ يُدرجان بالآلية نفسها. واشتقاق المنشأ من `nodeCount === 1` كان سيكذب
 * على قالب عقدة **حاوية** لها أبناء.
 *
 * إضافي بالكامل: الصفوف القائمة تأخذ `page` وهو منشؤها الحقيقي فعلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_templates', function (Blueprint $table) {
            $table->string('source', 8)->default('page')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('studio_templates', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};

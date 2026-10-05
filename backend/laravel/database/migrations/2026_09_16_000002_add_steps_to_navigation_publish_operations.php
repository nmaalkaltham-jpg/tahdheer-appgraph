<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر خطوات العمليّة.
 *
 * `status` و`current_step` يقولان أين وقفت العمليّة، ولا يقولان ما الذي
 * نُفِّذ قبل ذلك ولا متى ولا لماذا فشلت خطوةٌ بعينها. حوار «عقد النشر» كان
 * يطلب هذا الأثر (`data.steps`) ويتلقّى لا شيء، فيكتب «لم تُنفَّذ في آخر
 * عمليّة» عن خطواتٍ نُفِّذت فعلًا. هذا العمود هو الأثر.
 *
 * والإضافة مشروطة: قاعدة التطوير تحمل سجلّ هجرةٍ قديمة بالاسم نفسه حُذف
 * ملفّها، فالعمود موجودٌ فيها وغائبٌ عن قاعدةٍ تُبنى من الملفّات. الشرط
 * يجعل الاثنتين تنتهيان إلى الشكل نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('navigation_publish_operations', 'steps')) {
            return;
        }

        Schema::table('navigation_publish_operations', function (Blueprint $table) {
            $table->json('steps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('navigation_publish_operations', function (Blueprint $table) {
            $table->dropColumn('steps');
        });
    }
};

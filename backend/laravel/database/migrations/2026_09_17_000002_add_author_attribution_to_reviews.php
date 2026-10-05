<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صفة المراجِع ومدينته — «معلّمة علوم، الرياض».
 *
 * صفحة المنتج المعتمدة تعرض تحت اسم المراجِع سطرًا يصف دوره ومدينته، وهو ما
 * يفرّق شهادةَ معلّمةِ علومٍ في الرياض عن تعليقٍ مجهول السياق. ولا مصدر له
 * اليوم: `reviews` لا تحمله، و`customers` لا تحمل مدينةً أصلًا.
 *
 * ولماذا على التقييم لا على العميل؟ لأنّها **وصفٌ لحظةَ الكتابة** لا صفةٌ
 * دائمة: المعلّمة تنتقل مدينةً وتتغيّر صفتها، فنسبُها إلى الحساب يعيد كتابة
 * تقييماتٍ قديمة بوصفٍ لم يكن صاحبها عليه حين كتبها. ووضعُها على الحساب
 * يجعل تعديلَ الملفّ الشخصيّ يغيّر نصوصًا منشورة — وهو تزييفٌ بأثرٍ رجعيّ.
 *
 * وكلاهما اختياريّ ويُدخله صاحبه بنفسه في نموذج التقييم؛ التقييمات القائمة
 * تبقى بلا صفةٍ ولا مدينة، والواجهة تعرض الاسم وحده حينئذ ولا تخترع دورًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('reviews', 'author_role')) {
                $table->string('author_role', 120)->nullable()->after('body');
            }
            if (! Schema::hasColumn('reviews', 'author_city')) {
                $table->string('author_city', 80)->nullable()->after('author_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            foreach (['author_role', 'author_city'] as $column) {
                if (Schema::hasColumn('reviews', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * سياسة احتفاظٍ لسجلّ ٤٠٤ — تصحيحُ وعدٍ كان معروضًا بلا منفّذ.
 *
 * لوحة «الروابط المكسورة» تطبع «يُحذف بعد N يومًا منذ آخر ظهور»، وكان N
 * يأتي من `config('seo.not_found_retention_days', 90)` — مفتاحٍ **غير
 * معرَّف في `config/seo.php` أصلًا**، فالقيمة كانت الافتراض المثبَّت في سطر
 * الاستدعاء. ولا أمر تشذيبٍ يمسّ الجدول في المشروع كلّه. أي: رقمٌ مخترَع
 * يَعِد المشغّل بكنسٍ لا يقع، وسجلٌّ ينمو بلا حدّ.
 *
 * والمدّة تعيش هنا لا في الشيفرة، كما تنصّ قاعدة الاحتفاظ القائمة
 * (`AnalyticsPruneCommand`): `retention_policies` مصدر الحقيقة، وفيه
 * `legal_basis` مكتوبًا، ورقمٌ في الشيفرة يفترق عن السياسة عند أوّل تعديل.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('retention_policies')->updateOrInsert(
            ['data_class' => 'not_found_hits'],
            [
                'name' => 'سجلّ الروابط المكسورة (٤٠٤)',
                'retain_days' => 90,
                'on_expiry' => 'DELETE',
                'legal_basis' => 'تجميعةُ مسارٍ وعدّاد ومُحيلٍ — بلا زائر ولا جلسة. '
                    .'تُحفظ تسعين يومًا لأنّها نافذة إصلاح الروابط بعد ترحيلٍ أو إعادة تسمية، '
                    .'ثمّ لا قيمة تشغيليّة لمسارٍ لم يُطلَب فيها.',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('retention_policies')->where('data_class', 'not_found_hits')->delete();
    }
};

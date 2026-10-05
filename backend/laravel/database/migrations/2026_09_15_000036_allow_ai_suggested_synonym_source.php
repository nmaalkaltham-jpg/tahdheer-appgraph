<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مصدرٌ جديد لمرادفات البحث: `ai_suggested`.
 *
 * ─── لماذا مصدرٌ رابع لا إعادة استعمال `learned` ───
 *
 * القيد الحاليّ يسمح بـ`manual` و`learned` و`seed`. و«مُتعلَّم» في هذا
 * المشروع يعني مستخرَجًا من سجلّ البحث: مرادفٌ أثبتته نقرات الزوّار. واقتراح
 * نموذجٍ لغويّ شيءٌ آخر تمامًا — لا شاهد عليه إلّا النموذج نفسه.
 *
 * وخلطهما يُفقد المراجع القدرة على السؤال الوحيد المهمّ: «من أين جاء هذا
 * المرادف؟». فلو ساءت جودة النموذج لَتعذّر تمييز ما أنتجه عمّا أثبته السلوك،
 * ولا سبيل إلى سحب دفعةٍ واحدة دون الأخرى.
 *
 * ─── والحالة تبقى `pending` ───
 *
 * لا مرادفَ يدخل `active` من هذا الباب. مرادفٌ خاطئ يغيّر نتائج البحث لكلّ
 * زائر، ومراجعتُه في شاشة المرادفات القائمة أرخص من اكتشافه من شكوى.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE search_synonyms DROP CONSTRAINT IF EXISTS search_synonyms_source_check');
        DB::statement("ALTER TABLE search_synonyms ADD CONSTRAINT search_synonyms_source_check CHECK (source IN ('manual','learned','seed','ai_suggested'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * الرجوع يحذف صفوف الذكاء أوّلًا: قيدٌ يُعاد على صفوفٍ تخالفه يفشل،
         * فتبقى الهجرة عالقةً نصفها. وهذه الصفوف مرشَّحةٌ معلّقة لم تُستعمل.
         */
        DB::table('search_synonyms')->where('source', 'ai_suggested')->delete();
        DB::statement('ALTER TABLE search_synonyms DROP CONSTRAINT IF EXISTS search_synonyms_source_check');
        DB::statement("ALTER TABLE search_synonyms ADD CONSTRAINT search_synonyms_source_check CHECK (source IN ('manual','learned','seed'))");
    }
};

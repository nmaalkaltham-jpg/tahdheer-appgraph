<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * نوع إصدارٍ رابع: `create`.
 *
 * ─── لماذا ───
 *
 * النسخة الأولى من الصفحة كانت تُترك بلا صفٍّ في التاريخ، فيبدأ التاريخ من
 * النسخة الثانية. ونتيجةُ ذلك أنّ أيّ دمجٍ أو استرجاعٍ أساسُه النسخة ١ لا
 * يجد أساسًا — فتُرفض لقطةُ من أنشأ صفحةً وحرّرها ثمّ انهار متصفّحه، وهي
 * أوّل لحظةٍ يُحتاج فيها الاسترجاع أصلًا.
 *
 * ─── ولماذا نوعٌ مستقلّ لا `autosave` ───
 *
 * تقليم الحفظ التلقائيّ يُبقي آخر ٢٥ ويحذف ما قبلها. ولو سُجّلت النسخة
 * الأولى `autosave` لاختفت بعد ٢٥ حفظًا — فيعود العيب نفسه بعد نصف ساعة
 * تحرير. والنوع المستقلّ ينجو من التقليم كما ينجو `checkpoint` و`publish`.
 *
 * ─── الصفحات القائمة ───
 *
 * لا تُملأ بأثرٍ رجعيّ: وثيقتها الأصليّة غير محفوظة، واختراعُ صفٍّ لها كذبٌ
 * في سجلٍّ يُستعمل للاسترجاع. تُعلن «لا أساس» صادقةً.
 */
return new class extends Migration
{
    private const KINDS = ['autosave', 'checkpoint', 'publish', 'create'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE studio_page_versions DROP CONSTRAINT IF EXISTS studio_page_versions_kind');
        DB::statement('ALTER TABLE studio_page_versions ADD CONSTRAINT studio_page_versions_kind CHECK (
            kind IN ('."'".implode("','", self::KINDS)."'".')
        )');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE studio_page_versions DROP CONSTRAINT IF EXISTS studio_page_versions_kind');
        DB::statement("ALTER TABLE studio_page_versions ADD CONSTRAINT studio_page_versions_kind CHECK (
            kind IN ('autosave', 'checkpoint', 'publish')
        )");
    }
};

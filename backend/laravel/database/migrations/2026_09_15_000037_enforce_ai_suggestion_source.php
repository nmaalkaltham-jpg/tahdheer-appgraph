<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * القيد الذي كانت ثلاث ترويسات تَعِد به ولا وجود له.
 *
 * ─── ما كُشف ───
 *
 * `AiSuggestionStore` و`AiSuggestion` وهجرة الإنشاء كلّها تقول إنّ بقاء
 * اقتراح الآلة خارج `AttributeValue::CANONICAL_SOURCES` «مفروضٌ بقيدٍ في قاعدة
 * البيانات لا بانضباط الكاتب». وقد فُحصت الجداول فلم يكن هناك قيدٌ على
 * `source` أصلًا — ثلاثة قيودٍ على الحالة والاعتماد والثقة، ولا رابع.
 *
 * فكان الوعد أسوأ من غيابه: يقرأه المطوّر التالي فيطمئنّ إلى حارسٍ غير موجود،
 * ويكتب `source` قانونيًّا بسطرٍ واحد فيصير اقتراحُ نموذجٍ حقيقةً قانونيّة بلا
 * مراجعة (§٥٩، §١٦٠).
 *
 * ─── لماذا القائمة تُنسخ هنا ───
 *
 * `AttributeValue::CANONICAL_SOURCES` ثابتٌ في PHP، وقاعدة البيانات لا تقرؤه.
 * والنسخ في قيدٍ مقصود: القيد يحرس الجدول حتّى حين يُكتب فيه من خارج التطبيق
 * — من سكربتٍ أو من psql. وأيّ توسيعٍ للقائمة يستلزم هجرةً تُراجَع، وهو
 * المطلوب بالضبط في قاعدةٍ كهذه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE ai_suggestions ADD CONSTRAINT ai_suggestions_source_not_canonical '.
            "CHECK (source NOT IN ('VERIFIED_HUMAN','AUTHORITATIVE_SOURCE','CANONICAL_TAXONOMY','VERIFIED_SYSTEM'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ai_suggestions DROP CONSTRAINT IF EXISTS ai_suggestions_source_not_canonical');
    }
};

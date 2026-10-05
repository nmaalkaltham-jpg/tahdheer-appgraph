<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جلسةُ البحث المجهولة — `session_hash` (HMAC لمعرّف الجلسة، بموافقة القياس وحدها).
 *
 * ─── لماذا بهذا الاسم وهذا التاريخ ───
 *
 * طُبّقت على قاعدة التطوير في 2026-09-10 ولم يدخل ملفُّها المستودع (صفُّها في
 * `migrations` بلا ملفّ، والعمود مسجّلٌ «راسبًا» في known-schema-residue.json
 * لأنّ لا شيفرة كانت تكتبه). صار الآن يُكتب (`SearchTracking`)، فالاسم نفسه يجعلها
 * لا-عملًا على قاعدة التطوير ويُنشئ العمود في كلّ قاعدةٍ جديدة، بتعريفها حرفًا بحرف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_queries', function (Blueprint $table) {
            $table->char('session_hash', 64)->nullable();
            $table->index(['session_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('search_queries', function (Blueprint $table) {
            $table->dropIndex(['session_hash', 'created_at']);
            $table->dropColumn('session_hash');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تفضيلات المشغّل على الخادم — مظهر اللوحة وتخطيط مساحة العمل.
 *
 * عمودٌ على الحساب لا جدولٌ مستقلّ: التفضيل وثيقةٌ واحدة لمالكٍ واحد تُقرأ
 * وتُكتب كاملةً، ولا استعلام يعبرها. و`preferences_version` عدّادٌ يزيد مع
 * كلّ كتابة — به تُرفض كتابةٌ بُنيت على نسخةٍ قديمة (تبويبان مفتوحان) بدل
 * أن تدهس إحداهما الأخرى صامتةً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->jsonb('preferences')->nullable()->after('last_login_at');
            $table->unsignedInteger('preferences_version')->default(0)->after('preferences');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', fn (Blueprint $t) => $t->dropColumn(['preferences', 'preferences_version']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مهل القضايا وتصعيدها، ودورة حياةٍ كاملة.
 *
 * ─── العيب ───
 *
 * القضيّة الحرجة بلا مسؤولٍ كانت تجلس إلى الأبد ولا شيء يلاحظ. و«مفتوحة»
 * حالةٌ لا تقول متى كان يجب أن يُردّ عليها — فلا يُعرف المتأخّر من الجاري،
 * ولا يُقاس أداءُ التشغيل أصلًا.
 *
 * ─── دورة الحياة ───
 *
 *   NEW → ASSIGNED → INVESTIGATING → WAITING → RESOLVED → CLOSED
 *
 * و`RESOLVED` ليست `CLOSED`: بينهما **التحقّق**. من يعالج عيبًا ماليًّا
 * ليس من يشهد أنّه عولج، وطيُّ الخطوتين في واحدة يجعل الإغلاق ادّعاءً
 * لا إثباتًا.
 *
 * و`FALSE_POSITIVE` تنتقل من حالةٍ إلى **رمز قرار**: «لم يكن شيئًا» نتيجةُ
 * تحقيقٍ لا مرحلةٌ منه.
 *
 * ─── لماذا المهلة من الحدّة ───
 *
 * مهلةٌ واحدة لكلّ القضايا تعني إمّا إنذارًا مستمرًّا على التافه وإمّا
 * صمتًا عن الحرج. والحدّة هي الفرق الوحيد الذي يُعرف وقت الفتح.
 */
return new class extends Migration
{
    private const STATES = ['NEW', 'ASSIGNED', 'INVESTIGATING', 'WAITING', 'RESOLVED', 'CLOSED'];

    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            /* المهل تُحسب وقت الفتح وتُحفظ: قاعدةٌ تتغيّر غدًا يجب ألّا تُغيّر حكم أمس. */
            $table->timestampTz('first_response_due_at')->nullable()->after('opened_at');
            $table->timestampTz('resolution_due_at')->nullable()->after('first_response_due_at');
            $table->timestampTz('first_responded_at')->nullable()->after('resolution_due_at');
            $table->timestampTz('breached_at')->nullable()->after('first_responded_at');
            $table->unsignedSmallInteger('escalation_level')->default(0)->after('breached_at');

            /* ما بعد الحسم: لماذا وقع، وما يمنع تكراره. */
            $table->string('root_cause', 1000)->nullable()->after('resolution_note');
            $table->string('preventive_action', 1000)->nullable()->after('root_cause');
        });

        // `dedupe_key` هو مفتاح التكرار — يُسمّى باسمه.
        DB::statement('ALTER TABLE cases RENAME COLUMN dedupe_key TO recurrence_key');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * ترحيل الحالات القائمة. لا قضايا اليوم، والترحيل مكتوبٌ على أيّ حال:
         * هجرةٌ تفترض جدولًا فارغًا تكسر أوّل نشرٍ على بيانات.
         */
        DB::statement("UPDATE cases SET state = 'NEW' WHERE state = 'OPEN'");
        DB::statement("UPDATE cases SET state = 'WAITING' WHERE state = 'ACTION_REQUIRED'");
        DB::statement("UPDATE cases SET state = 'RESOLVED', resolution_code = 'FALSE_POSITIVE'
            WHERE state = 'FALSE_POSITIVE'");

        DB::statement('ALTER TABLE cases DROP CONSTRAINT IF EXISTS cases_state_check');
        DB::statement('ALTER TABLE cases ADD CONSTRAINT cases_state_check CHECK (
            state IN ('."'".implode("','", self::STATES)."'".')
        )');

        DB::statement('DROP INDEX IF EXISTS cases_open_dedupe_idx');
        DB::statement("CREATE UNIQUE INDEX cases_open_recurrence_idx ON cases (kind, recurrence_key)
            WHERE recurrence_key IS NOT NULL AND state <> 'CLOSED'");

        DB::statement('ALTER TABLE cases DROP CONSTRAINT IF EXISTS cases_closed_is_explained');
        DB::statement("ALTER TABLE cases ADD CONSTRAINT cases_closed_is_explained CHECK (
            state NOT IN ('RESOLVED', 'CLOSED')
            OR (resolved_at IS NOT NULL AND resolution_code IS NOT NULL)
        )");

        /* التصعيد لا يقع بلا خرق: رقمٌ يرتفع بلا سببٍ مسجَّل لا يُقرأ. */
        DB::statement('ALTER TABLE cases ADD CONSTRAINT cases_escalation_needs_breach CHECK (
            escalation_level = 0 OR breached_at IS NOT NULL
        )');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS cases_open_recurrence_idx');
            DB::statement('ALTER TABLE cases DROP CONSTRAINT IF EXISTS cases_escalation_needs_breach');
            DB::statement('ALTER TABLE cases RENAME COLUMN recurrence_key TO dedupe_key');
        }

        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn([
                'first_response_due_at', 'resolution_due_at', 'first_responded_at',
                'breached_at', 'escalation_level', 'root_cause', 'preventive_action',
            ]);
        });
    }
};

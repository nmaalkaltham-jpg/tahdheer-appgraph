<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * جداول محرّك الذكاء — ثلاثة لا أكثر.
 *
 * ─── لماذا `ai_runs` لا `ai_requests` ───
 *
 * `ai_requests` اسمٌ محجوز: جدولٌ بهذا الاسم قائمٌ في قاعدة التطوير راسبًا من
 * شيفرةٍ حُذفت، ومسجَّلٌ في `scripts/known-schema-residue.json`. وذلك السجلّ
 * يفشل إذا صار لأيّ مدخلٍ فيه هجرةٌ حقيقيّة — وهو فحصٌ مقصود. فالاسم الجديد
 * يتجنّب الالتباس ويُبقي الفحص عاملًا.
 *
 * ─── ولماذا الاقتراحات منفصلةٌ عن التشغيل ───
 *
 * `ai_runs` أثرُ تشغيلٍ يُقلَّم بالزمن: لا يحتاج أحدٌ نداءً من العام الماضي.
 * و`ai_suggestions` بياناتٌ تنتظر قرار إنسان، وقد تبقى شهورًا. دمجُهما يعني
 * إمّا تقليمًا يحذف اقتراحًا لم يُبتّ فيه، أو سجلَّ تشغيلٍ ينمو بلا حدّ.
 *
 * ─── ولا موجّهٌ يُخزَّن مع النداء ───
 *
 * `ai_runs` يحمل **بصمة** المدخل لا نصّه: النصّ قد يحوي اسم عميلٍ أو محتوى
 * ملفٍّ مدفوع، وتخزينه في سجلٍّ تشغيليّ يقرؤه كلّ من يملك `audit.read` تسريبٌ
 * بلا حاجة. البصمة تكفي للخمول والتخزين المؤقّت وكشف التكرار.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * سجلّ الموجّهات — مُصدَّرٌ لا مُعدَّل.
         *
         * الموجّه يتغيّر فتتغيّر معه جودة الخرج. وتعديلُه في مكانه يجعل
         * اقتراحًا قديمًا يبدو كأنّه خرج من الموجّه الجديد، فلا يُفسَّر تدهورٌ
         * ولا يُنسب تحسّن. النسخة صفٌّ جديد، والقديم يبقى مقروءًا.
         */
        Schema::create('ai_prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('prompt_id', 120);
            $table->unsignedInteger('version');
            $table->string('name', 200);
            $table->string('task', 40);
            $table->text('system_prompt');
            $table->text('user_template');
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            /*
             * الحرارة بالآلاف عددًا صحيحًا لا عشريًّا: المقارنة العشريّة في
             * SQL تفاجئ، و`0.2` تُخزَّن `200` فتُقارَن وتُفهرَس بلا لبس.
             */
            $table->unsignedSmallInteger('temperature_milli')->default(200);
            $table->unsignedInteger('max_tokens')->default(800);
            $table->unsignedSmallInteger('timeout_seconds')->default(60);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['prompt_id', 'version']);
            $table->index(['prompt_id', 'enabled']);
        });

        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('task', 40);
            $table->string('prompt_id', 120);
            $table->unsignedInteger('prompt_version');
            $table->string('model', 120);
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('actor_admin_user_id')->nullable();
            $table->char('input_hash', 64);
            $table->string('status', 20);
            $table->unsignedInteger('latency_ms')->default(0);
            /*
             * «مقدَّرة» في الاسم عمدًا: llama.cpp يعيد عدّ الرموز فعليًّا،
             * لكنّ محرّكًا آخر قد يقدّرها. الاسم يمنع بناء محاسبةٍ ماليّة على
             * رقمٍ قد يكون تقديرًا.
             */
            $table->unsignedInteger('input_units')->default(0);
            $table->unsignedInteger('output_units')->default(0);
            $table->boolean('cached')->default(false);
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['task', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->string('field', 80);
            $table->string('task', 40);
            $table->json('value');
            $table->string('model', 120);
            $table->string('prompt_id', 120);
            $table->unsignedInteger('prompt_version');
            $table->char('input_hash', 64);
            /*
             * الثقة تُكتب كما أعلنها النموذج ولا تُحسب. `null` حين لا يعلنها —
             * وصفرٌ يُقرأ «واثقٌ أنّه خاطئ» وهو معنًى آخر تمامًا.
             */
            $table->unsignedSmallInteger('confidence_pct')->nullable();
            /*
             * المصدر يبقى خارج `AttributeValue::CANONICAL_SOURCES` دائمًا.
             * اقتراح الآلة يبقى مرشَّحًا حتّى يعتمده إنسان (§٥٩، §١٦٠).
             */
            $table->string('source', 40)->default('AI_SUGGESTED');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedBigInteger('approved_by_admin_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'status']);
            $table->index(['status', 'created_at']);
            /*
             * اقتراحٌ واحدٌ معلّق لكلّ حقلٍ ومدخلٍ متطابق: إعادة التشغيل على
             * محتوًى لم يتغيّر لا تُنتج صفًّا ثانيًا يراه المشرف مرّتين.
             */
            $table->unique(['entity_type', 'entity_id', 'field', 'input_hash'], 'ai_suggestions_identity_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('SUCCEEDED','FAILED','REJECTED'))");
            DB::statement("ALTER TABLE ai_suggestions ADD CONSTRAINT ai_suggestions_status_check CHECK (status IN ('PENDING','APPROVED','REJECTED','SUPERSEDED'))");
            // اعتمادٌ بلا معتمِدٍ ووقت يجعل الأثر بلا مسؤول.
            DB::statement('ALTER TABLE ai_suggestions ADD CONSTRAINT ai_suggestions_approval_tracked CHECK (status <> \'APPROVED\' OR (approved_by_admin_user_id IS NOT NULL AND approved_at IS NOT NULL))');
            DB::statement('ALTER TABLE ai_suggestions ADD CONSTRAINT ai_suggestions_confidence_range CHECK (confidence_pct IS NULL OR confidence_pct <= 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_prompt_versions');
    }
};

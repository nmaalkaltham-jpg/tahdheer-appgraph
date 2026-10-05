<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * القضيّة: بدائيّةٌ واحدة تخدم الاحتيال والحوادث والدعم (§١٩ §٢٨ §٥٤).
 *
 * ─── لماذا جدولٌ واحد لثلاثة نطاقات ───
 *
 * الثلاثة تطلب الشيء نفسه: حالةٌ تتقدّم، ومسؤولٌ يُسنَد إليها، وخيطٌ زمنيّ
 * يُقرأ، وقرارٌ يُسجَّل برمزه. وبناؤها ثلاث مرّات يُنتج ثلاث آلات حالاتٍ
 * تتباعد، وثلاث شاشاتٍ تتصرّف على نحوٍ مختلف أمام الفعل نفسه.
 *
 * وميزانيّة التعقيد (§٨٠) تقول: بدائيّةٌ واحدة × عشر استعمالات خيرٌ من عشر
 * تنفيذاتٍ منعزلة.
 *
 * ─── لماذا `dedupe_key` ───
 *
 * الحادثة تتكرّر: مزوّدٌ يسقط فيُنتج خمسمئة خطأ في دقيقة. وقضيّةٌ لكلّ خطأ
 * تُغرق الشاشة فيصير الإنذار ضجيجًا يُتجاهَل — وهو أسوأ من غياب الإنذار.
 * المفتاح فريدٌ على **المفتوح** وحده: تُفتح واحدة، ويُضاف ما بعدها إلى
 * خيطها، وتُفتح جديدة بعد الإغلاق إن عاد العطل.
 */
return new class extends Migration
{
    private const KINDS = ['FRAUD', 'INCIDENT', 'SUPPORT'];

    /** `FALSE_POSITIVE` نهائيّةٌ مستقلّة: «لم يكن شيئًا» ليست «عولج». */
    private const STATES = ['OPEN', 'INVESTIGATING', 'ACTION_REQUIRED', 'RESOLVED', 'FALSE_POSITIVE'];

    private const SEVERITIES = ['INFO', 'WARNING', 'HIGH', 'CRITICAL'];

    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', self::KINDS);
            $table->enum('state', self::STATES)->default('OPEN');
            $table->enum('severity', self::SEVERITIES)->default('WARNING');

            $table->string('title', 191);

            /* الموضوع مرجعٌ لا نسخة: القضيّة تشير ولا تنسخ حالة نطاقٍ آخر. */
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();

            $table->unsignedBigInteger('assigned_to_admin_user_id')->nullable();
            $table->unsignedBigInteger('opened_by_admin_user_id')->nullable();

            /* رموز الأسباب: القضيّة تُفتح **لماذا** لا «لأنّ شيئًا حدث». */
            $table->json('reason_codes')->nullable();

            $table->string('dedupe_key', 191)->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->string('resolution_code', 64)->nullable();
            $table->string('resolution_note', 1000)->nullable();

            $table->timestampTz('opened_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'state']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('customer_id');
            $table->index('assigned_to_admin_user_id');
        });

        Schema::create('case_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('actor_admin_user_id')->nullable();

            $table->string('action', 64);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32)->nullable();
            $table->string('note', 2000)->nullable();

            /* الدليل المرفق: إشارات، أو معرّفات، أو حمولة مزوّد. */
            $table->json('evidence')->nullable();

            $table->timestampTz('created_at');

            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $table->index(['case_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * الفرادة على **المفتوح** وحده.
         *
         * فهرسٌ جزئيّ لا قيدٌ كامل: العطل نفسه قد يعود بعد شهر، وقضيّةٌ
         * جديدة له حينئذٍ صحيحة. أمّا خمسمئة قضيّة في دقيقةٍ واحدة فضجيج.
         */
        DB::statement("CREATE UNIQUE INDEX cases_open_dedupe_idx ON cases (kind, dedupe_key)
            WHERE dedupe_key IS NOT NULL AND state NOT IN ('RESOLVED', 'FALSE_POSITIVE')");

        // ما أُغلق له وقتُ إغلاق، وما أُغلق له رمزُ قرار. الإغلاق بلا سببٍ نسيان.
        DB::statement("ALTER TABLE cases ADD CONSTRAINT cases_closed_is_explained CHECK (
            state NOT IN ('RESOLVED', 'FALSE_POSITIVE')
            OR (resolved_at IS NOT NULL AND resolution_code IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('case_events');
        Schema::dropIfExists('cases');
    }
};

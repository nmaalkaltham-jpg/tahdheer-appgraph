<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الخصوصيّة ونظام حماية البيانات الشخصيّة — المرحلة ٠٤٤ (§١٠٦).
 *
 * ─── التوتّر الذي يحكم هذا الملفّ ───
 *
 * «احذف بياناتي» و«احتفظ بالفواتير» أمران قانونيّان متعارضان: النظام يمنح
 * صاحب البيانات حقّ المحو، ونظام الفوترة يوجب الاحتفاظ بسجلّ المعاملة.
 * حذفٌ كامل يخالف الثاني، ورفضُ الحذف يخالف الأوّل.
 *
 * والحلّ الصادق **إخفاء الهويّة لا الحذف**: يُمحى الشخص ويبقى الأثر
 * الماليّ. الطلب يبقى بمبلغه وضريبته وتاريخه، ولا يبقى فيه اسمٌ ولا بريدٌ
 * ولا هاتف. فيُرضى الحقّان معًا بلا أن يكذب أحدهما على الآخر.
 *
 * ─── لماذا الموافقة تُسحب ولا تُحذف ───
 *
 * سجلّ الموافقة هو **دليل الامتثال**. حذفُه عند السحب يُفقد المتجر القدرةَ
 * على إثبات أنّه كان مأذونًا له يوم أرسل. فالسحب يُسجَّل حدثًا جديدًا،
 * والسجلّ القديم يبقى بتاريخه ونسخة النصّ الذي وُوفق عليه.
 */
return new class extends Migration
{
    /** §١٠٦ — أغراض المعالجة. لكلٍّ أساسه النظاميّ المستقلّ. */
    private const PURPOSES = [
        'TERMS', 'PRIVACY_POLICY', 'MARKETING_EMAIL', 'MARKETING_SMS',
        'ANALYTICS', 'PERSONALIZATION', 'FUNCTIONAL_COOKIES',
    ];

    /** حقوق صاحب البيانات. */
    private const REQUEST_TYPES = [
        'ACCESS', 'EXPORT', 'DELETION', 'RECTIFICATION', 'OBJECTION', 'RESTRICTION',
    ];

    private const REQUEST_STATES = [
        'RECEIVED', 'IDENTITY_PENDING', 'IN_PROGRESS', 'COMPLETED', 'REFUSED', 'PARTIALLY_COMPLETED',
    ];

    public function up(): void
    {
        /*
         * سجلّ الموافقة — يُضاف ولا يُعدَّل.
         *
         * `policy_version` و`wording_shown` ضروريّان: إثبات الموافقة يحتاج
         * معرفة **ما الذي وُوفق عليه**، لا أنّ زرًّا نُقر.
         */
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            /* مرجعٌ لمن لا حساب له بعد — تجزئةُ بريدٍ أو معرّف جلسة. */
            $table->string('subject_ref', 64)->nullable();

            $table->enum('purpose', self::PURPOSES);
            $table->boolean('granted');
            $table->string('policy_version');
            $table->string('wording_shown', 1000)->nullable();
            $table->enum('source', ['CHECKOUT', 'BANNER', 'ACCOUNT', 'IMPORT', 'SUPPORT']);

            /* دليلٌ مُجزَّأ لا خام (§١٠٦): يُثبت ولا يحتفظ بمعرّفٍ شخصيّ. */
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();

            $table->timestamp('recorded_at')->useCurrent();

            $table->index(['customer_id', 'purpose', 'recorded_at']);
            $table->index(['subject_ref', 'purpose']);
        });

        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();          // DSAR-…
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_email');
            $table->enum('type', self::REQUEST_TYPES);
            $table->enum('status', self::REQUEST_STATES)->default('RECEIVED');

            /*
             * المهلة النظاميّة تُحسب عند الاستلام وتُخزَّن.
             *
             * حسابُها عند القراءة يجعلها تتحرّك مع تغيّر الإعداد، فيبدو طلبٌ
             * تأخّر وكأنّه في وقته. التاريخ المخزَّن هو الوعد الذي قُطع.
             */
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('due_at');
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('refusal_reason', 1000)->nullable();
            /* ما سُلّم فعلًا: تجزئةُ الملفّ لا محتواه. */
            $table->string('export_sha256', 64)->nullable();
            $table->unsignedInteger('export_bytes')->nullable();
            $table->string('note', 1000)->nullable();

            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index('contact_email');
        });

        /* §١٠٦ — سياسات الاحتفاظ، ولكلٍّ أساسٌ نظاميّ مكتوب. */
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('data_class')->unique();   // orders|invoices|download_events|risk|analytics
            $table->string('name');
            $table->unsignedInteger('retain_days');
            $table->enum('on_expiry', ['ANONYMIZE', 'DELETE', 'ARCHIVE']);
            /* «لماذا نحتفظ بهذا؟» سؤالٌ نظاميّ له جوابٌ مكتوب أو لا يُحتفظ. */
            $table->string('legal_basis', 500);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        /*
         * الحجز القانونيّ — يغلب حقّ المحو.
         *
         * نزاعٌ أو تحقيقٌ جارٍ يوجب تجميد البيانات. بلا هذا الجدول يكون
         * الامتثال لطلب محوٍ إتلافًا لدليل.
         */
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');            // customer|order
            $table->unsignedBigInteger('subject_id');
            $table->string('reason', 1000);
            $table->string('reference')->nullable();   // رقم قضيّة أو بلاغ
            $table->unsignedBigInteger('placed_by_admin_user_id')->nullable();
            $table->timestamp('placed_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->string('release_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'released_at']);
        });

        /*
         * أثر إخفاء الهويّة. الشخص يُمحى ويبقى **أنّ المحو وقع**: بغير ذلك
         * يستحيل إثبات الامتثال، ويبدو الطلب المجهول الاسم عطلًا في البيانات.
         */
        Schema::create('anonymization_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_subject_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('fields_cleared');
            $table->unsignedInteger('records_affected')->default(0);
            $table->string('retained_for', 500)->nullable(); // ما بقي ولماذا
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // المهلة بعد الاستلام دائمًا — تاريخٌ مقلوب يجعل كلّ طلبٍ متأخّرًا.
            DB::statement('ALTER TABLE data_subject_requests ADD CONSTRAINT dsar_due_after_received CHECK (due_at > received_at)');

            DB::statement("ALTER TABLE data_subject_requests ADD CONSTRAINT dsar_completed_has_time CHECK (
                status NOT IN ('COMPLETED', 'PARTIALLY_COMPLETED') OR completed_at IS NOT NULL
            )");

            /*
             * الرفض يحتاج سببًا: النظام يمنح صاحب البيانات حقّ معرفة **لماذا**
             * رُفض طلبه، ورفضٌ صامت مخالفةٌ لا نقصُ تهذيب.
             */
            DB::statement("ALTER TABLE data_subject_requests ADD CONSTRAINT dsar_refusal_has_reason CHECK (
                status <> 'REFUSED' OR refusal_reason IS NOT NULL
            )");

            /*
             * لا يُنفَّذ محوٌ قبل التحقّق من الهويّة. تسليمُ بيانات شخصٍ لمن
             * ادّعى أنّه هو خرقٌ أسوأ من التأخّر في الردّ.
             */
            DB::statement("ALTER TABLE data_subject_requests ADD CONSTRAINT dsar_completion_requires_identity CHECK (
                status NOT IN ('COMPLETED', 'PARTIALLY_COMPLETED') OR identity_verified_at IS NOT NULL
            )");

            DB::statement('ALTER TABLE legal_holds ADD CONSTRAINT legal_holds_released_has_note CHECK (
                released_at IS NULL OR release_note IS NOT NULL
            )');

            DB::statement('ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_days_positive CHECK (retain_days > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('anonymization_log');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('data_subject_requests');
        Schema::dropIfExists('consent_records');
    }
};

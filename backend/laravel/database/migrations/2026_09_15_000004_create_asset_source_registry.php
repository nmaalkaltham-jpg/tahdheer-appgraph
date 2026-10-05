<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ مصادر الأصول — المراحل ٠١٧–٠١٩ (§١١ §١٢ §١٣ §١٦).
 *
 * `assets` اليوم يصف **النسخة التي نملكها**: تجزئتها وحجمها ونوعها وموضعها.
 * وهو لا يصف **من أين جاءت** ولا هل ما تزال هناك. عمود `provider` نصُّه
 * `local` في الصفوف الستّة والعشرين كلّها، و`provider_ref` مرجعٌ داخليّ لا
 * هويّةٌ خارجيّة. فلا مكان اليوم لملفّ Google Drive: لا معرّفه، ولا مراجعته،
 * ولا حالة مزامنته، ولا متى تُحقّق منه آخر مرّة.
 *
 * هذا الجدول يفصل الأمرين. «Google Drive محوّل مصدر لا نطاق تجاريّ» (§١٦٤):
 * المنتج لا يعرف Drive، والأصل لا يعرفه، وإنّما يعرفه صفٌّ هنا يحمل عقدًا
 * واحدًا تنفّذه كلّ المصادر (`DigitalAssetSource`).
 *
 * والهويّة الدائمة هي معرّف المزوّد لا اسم الملفّ (§١٣): الاسم يتغيّر بنقرة،
 * والمعرّف يبقى. وهذا ما يجعل «الملفّ نُقل» قابلًا للتمييز عن «الملفّ حُذف».
 *
 * ما لا يُخزَّن هنا عمدًا (§١٥): لا رموز OAuth، ولا أسرار خدمة، ولا بنية
 * مجلّدات خاصّة، ولا صلاحيات مشرف Drive. `account_ref` مرجعٌ إلى اعتمادٍ
 * يعيش في خزنة الأسرار، لا الاعتماد نفسه.
 */
return new class extends Migration
{
    /** §١١ — محوّلات المصادر المدعومة. */
    private const PROVIDERS = [
        'DIRECT_UPLOAD', 'INTERNAL_OBJECT_STORAGE', 'GOOGLE_DRIVE', 'ONEDRIVE',
        'DROPBOX', 'REMOTE_SECURE_STORAGE', 'S3_COMPATIBLE_STORAGE',
        'EXTERNAL_PROVIDER', 'GENERATED_FILE',
    ];

    /** §١٤ — أنماط التسليم الثلاثة، صريحةً لا مستنتَجة. */
    private const DELIVERY_MODES = ['IMPORT_ONCE', 'MIRROR_SYNC', 'EXTERNAL_ACCESS_BROKER'];

    /** §١٦ — حالات المزامنة. */
    private const SYNC_STATES = [
        'CURRENT', 'SOURCE_CHANGED', 'SYNC_PENDING', 'SYNC_FAILED',
        'SOURCE_MISSING', 'ACCESS_REVOKED',
    ];

    public function up(): void
    {
        Schema::create('asset_sources', function (Blueprint $table) {
            $table->id();

            /*
             * الأصل المستورَد. فارغٌ مشروع لـEXTERNAL_ACCESS_BROKER وحده:
             * ذلك النمط لا يملك نسخةً داخليّة أصلًا، والوسيط يأذن ثمّ يحيل.
             */
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('provider', self::PROVIDERS);
            $table->enum('delivery_mode', self::DELIVERY_MODES);

            /* §١٣ — الهويّة الدائمة: معرّف المزوّد، لا اسم الملفّ أبدًا. */
            $table->string('external_id');
            $table->string('external_parent_id')->nullable(); // drive_id / معرّف المجلّد
            $table->string('account_ref')->nullable();        // مرجعٌ إلى خزنة الأسرار، لا سرّ

            /* بيانات المزوّد كما يصرّح بها هو — قد تُخالف ما نملك، وذاك الخلاف إشارة. */
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            /* §١٦ — حوكمة المزامنة: مراجعتان لا واحدة، فالفرق بينهما هو الإشارة. */
            $table->string('source_revision')->nullable();
            $table->string('imported_revision')->nullable();
            $table->timestamp('source_modified_at')->nullable();
            $table->string('content_hash', 64)->nullable();

            $table->enum('sync_state', self::SYNC_STATES)->default('SYNC_PENDING');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);

            /* §١٦ — تجميد النسخة: مصدرٌ تغيّر لا يُسحب تحت أقدام من اشترى. */
            $table->boolean('version_frozen')->default(false);

            $table->timestamps();

            // ملفٌّ واحد عند مزوّدٍ واحد = صفٌّ واحد. التكرار خطأ تسجيلٍ لا حالة.
            $table->unique(['provider', 'external_id']);
            $table->index(['sync_state', 'last_verified_at']);
            $table->index('asset_id');
        });

        /*
         * سجلّ محاولات المزامنة. بدونه يصير «فشل» عمودًا يُكتب فوقه، فتضيع
         * الحالة السابقة ولا يمكن الإجابة عن «متى بدأ هذا يفشل ولماذا» —
         * وهدف §١٦٠ صفرُ إخفاقات مزامنةٍ غير مُفسَّرة، لا صفرُ إخفاقات.
         */
        Schema::create('asset_source_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_source_id')->constrained()->cascadeOnDelete();
            $table->string('trigger'); // manual|scheduled|purchase_check|webhook
            $table->enum('outcome', ['SUCCESS', 'NO_CHANGE', 'FAILED', 'SKIPPED_FROZEN']);
            $table->string('from_revision')->nullable();
            $table->string('to_revision')->nullable();
            $table->string('message', 1000)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['asset_source_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            /*
             * النمطان اللذان يملكان نسخةً داخليّة يوجبان أصلًا حين تكون
             * الحالة CURRENT. «حاليّ بلا نسخة» تناقضٌ يصمت عنه الجدول إن لم
             * يُمنع، فيُسلَّم للزبون لا شيء بينما تقول اللوحة إنّ كلّ شيء سليم.
             */
            DB::statement("ALTER TABLE asset_sources ADD CONSTRAINT asset_sources_owned_copy_present CHECK (
                delivery_mode = 'EXTERNAL_ACCESS_BROKER' OR sync_state <> 'CURRENT' OR asset_id IS NOT NULL
            )");

            /*
             * قيدٌ حُذف قصدًا: «الوسيط الخارجيّ لا يرتبط بأصل».
             * كان يخلط أمرين — امتلاك البايتات، وامتلاك سجلٍّ يصفها. صفُّ
             * `assets` بمزوّد `external` هو الثاني وحده: بياناتٌ عن ملفٍّ لا
             * نخزّنه. منعُ الربط كان يُيتّم ذلك الصفّ بلا مقابل، فالقيد
             * المفيد هو التالي وحده.
             */
            // §١٦ — CURRENT تعني المراجعتين متطابقتين. غير ذلك حالةٌ أخرى باسمها.
            DB::statement("ALTER TABLE asset_sources ADD CONSTRAINT asset_sources_current_means_synced CHECK (
                sync_state <> 'CURRENT'
                OR delivery_mode = 'EXTERNAL_ACCESS_BROKER'
                OR source_revision IS NOT DISTINCT FROM imported_revision
            )");

            // فشلٌ بلا سبب مكتوب لا يُفسَّر لاحقًا (§١٦٠).
            DB::statement("ALTER TABLE asset_sources ADD CONSTRAINT asset_sources_failure_has_reason CHECK (
                sync_state NOT IN ('SYNC_FAILED', 'SOURCE_MISSING', 'ACCESS_REVOKED') OR last_error IS NOT NULL
            )");

            // §١٣ — المعرّف الخارجيّ ليس فراغًا: الفراغ اسمُ ملفٍّ ضائع لا هويّة.
            DB::statement('ALTER TABLE asset_sources ADD CONSTRAINT asset_sources_external_id_present CHECK (length(btrim(external_id)) > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_source_syncs');
        Schema::dropIfExists('asset_sources');
    }
};

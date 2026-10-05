<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نموذج معلومات المنتج والحزم والعلاقات — المراحل ٠١٤ و٠١٦ و§٣٥ §٣٦ §٥٧.
 *
 * §٤٩ تمنع «كتلة وصفٍ واحدة غير مبنيَّة»، وليس لأسبابٍ جماليّة: الوصف
 * الحرّ لا يُستخرج منه «ما الذي يتضمّنه المنتج؟» ولا «بأيّ صيغةٍ يأتي؟»،
 * فلا تبلغه إجابةٌ في بحثٍ ولا مقتطفٌ في نتيجة ولا بياناتٌ مبنيَّة. الأقسام
 * المسمّاة تجعل السؤال قابلًا للإجابة آليًّا.
 *
 * و§٣٦ تمنع تكرار حقائق المنتج داخل الحزمة: الحزمة **رسمُ مكوّنات** يشير
 * إلى صيغٍ قائمة. نسخُ العنوان والسعر داخلها يجعل تعديلَ المكوّن يترك
 * الحزمة تكذب، وهو خطأٌ لا يُكتشف إلّا من شكوى زبون.
 *
 * و§٥٧ توجب لكلّ علاقةٍ مصدرًا: «مرتبطٌ بـ» التي اقترحها نموذجٌ ليست
 * كـ«الجزء التالي في المنهج» التي أثبتها معلّم. بلا المصدر يستحيل مراجعة
 * التوصيات لاحقًا أو التراجع عن دفعةٍ خاطئة.
 */
return new class extends Migration
{
    /** §٤٩ — الأقسام المسمّاة. */
    private const SECTION_KINDS = [
        'SUMMARY', 'FEATURES', 'BENEFITS', 'INCLUDED_ITEMS', 'SPECIFICATIONS',
        'COMPATIBILITY', 'LEARNING_OBJECTIVES', 'FILE_INFORMATION', 'LICENSE',
        'DELIVERY', 'USAGE_INSTRUCTIONS', 'REQUIREMENTS', 'LIMITATIONS',
        'CARE_INSTRUCTIONS', 'WARRANTY', 'RETURN_INFORMATION', 'FAQ',
    ];

    /** §٥٧ — أنواع العلاقات. */
    private const RELATION_TYPES = [
        'RELATED_TO', 'ALTERNATIVE_TO', 'COMPLEMENTARY_TO', 'REPLACEMENT_FOR',
        'ACCESSORY_FOR', 'COMPATIBLE_WITH', 'PART_OF', 'BUNDLE_COMPONENT',
        'UPSELL', 'CROSS_SELL', 'NEXT_IN_CURRICULUM', 'PREVIOUS_IN_CURRICULUM',
    ];

    /** §٥٩ — مرتبة الحقيقة، كما في سجلّ السمات. */
    private const SOURCES = [
        'VERIFIED_HUMAN', 'AUTHORITATIVE_SOURCE', 'CANONICAL_TAXONOMY', 'VERIFIED_SYSTEM',
        'HIGH_CONFIDENCE_EXTRACTION', 'OCR_CANDIDATE', 'AI_SUGGESTION', 'BEHAVIORAL_SIGNAL',
    ];

    public function up(): void
    {
        /* §٤٩ — محتوى مبنيٌّ بأقسامٍ مسمّاة بدل كتلةٍ واحدة. */
        Schema::create('product_content_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', self::SECTION_KINDS);
            $table->string('heading')->nullable();
            $table->longText('body')->nullable();
            /* قائمةٌ مبنيَّة حين يكون القسم عناصر — «ما يتضمّنه» و«المزايا». */
            $table->json('items')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('locale', 12)->nullable(); // فارغ = اللغة الافتراضيّة
            $table->enum('source', self::SOURCES)->default('VERIFIED_HUMAN');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['product_group_id', 'position']);
        });

        /* §٥٧ — رسم العلاقات، ولكلّ علاقةٍ مصدر. */
        Schema::create('product_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_product_group_id')->constrained('product_groups')->cascadeOnDelete();
            $table->foreignId('to_product_group_id')->constrained('product_groups')->cascadeOnDelete();
            $table->enum('relation_type', self::RELATION_TYPES);
            $table->unsignedInteger('position')->default(0);
            $table->enum('source', self::SOURCES)->default('VERIFIED_HUMAN');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['from_product_group_id', 'to_product_group_id', 'relation_type']);
            $table->index(['to_product_group_id', 'relation_type']);
        });

        /* §٣٥ — نوع الحزمة على المجموعة. فارغ = ليست حزمة. */
        Schema::table('product_groups', function (Blueprint $table) {
            $table->enum('bundle_kind', ['FIXED', 'CONFIGURABLE', 'DYNAMIC', 'KIT', 'CURATED'])
                ->nullable()->after('product_nature');
        });

        /*
         * §٣٦ — مكوّنات الحزمة: إشاراتٌ إلى صيغٍ قائمة لا نسخٌ من حقائقها.
         *
         * `selection_group` هو ما يجعل الحزمة القابلة للتهيئة ممكنة: مكوّنات
         * تحمل المجموعة نفسها بدائلُ يُختار منها، لا إضافاتٌ تُجمع.
         */
        Schema::create('bundle_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_product_group_id')->constrained('product_groups')->cascadeOnDelete();
            $table->foreignId('component_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('required')->default(true);
            $table->string('selection_group')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['bundle_product_group_id', 'component_variant_id', 'selection_group']);
            $table->index('component_variant_id');
        });

        /* §٥٥ — حالات الاستعمال. */
        Schema::create('use_cases', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('product_use_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('use_case_id')->constrained()->cascadeOnDelete();
            $table->enum('source', self::SOURCES)->default('VERIFIED_HUMAN');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->timestamps();

            $table->unique(['product_group_id', 'use_case_id']);
        });

        /* §٥٦ — رسم التوافق: مع منصّةٍ أو جهازٍ أو برنامج. */
        Schema::create('product_compatibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->cascadeOnDelete();
            $table->enum('target_kind', ['PLATFORM', 'DEVICE', 'SOFTWARE', 'PRODUCT', 'FILE_FORMAT']);
            $table->string('target_name');
            $table->string('minimum_version')->nullable();
            $table->string('note', 500)->nullable();
            $table->enum('source', self::SOURCES)->default('VERIFIED_HUMAN');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->timestamps();

            $table->unique(['product_group_id', 'target_kind', 'target_name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            /*
             * منتجٌ مرتبطٌ بنفسه حلقةٌ تُغرق كلّ عارضٍ يتبع العلاقات: «مرتبط
             * بـ» تعود إلى المنتج نفسه فيدور المتصفّح بلا نهاية.
             */
            DB::statement('ALTER TABLE product_relationships ADD CONSTRAINT product_relationships_no_self_link CHECK (
                from_product_group_id <> to_product_group_id
            )');
            DB::statement('ALTER TABLE product_relationships ADD CONSTRAINT product_relationships_confidence_range CHECK (
                confidence IS NULL OR confidence BETWEEN 0 AND 100
            )');
            DB::statement('ALTER TABLE bundle_components ADD CONSTRAINT bundle_components_quantity_positive CHECK (quantity > 0)');
            /*
             * قسمٌ فارغٌ تمامًا — بلا نصٍّ ولا عناصر — يُصيَّر عنوانًا معلّقًا
             * في الصفحة ويُدخل قسمًا فارغًا في البيانات المبنيَّة.
             */
            DB::statement('ALTER TABLE product_content_sections ADD CONSTRAINT product_content_sections_not_empty CHECK (
                body IS NOT NULL OR items IS NOT NULL
            )');
            DB::statement('ALTER TABLE product_content_sections ADD CONSTRAINT product_content_sections_confidence_range CHECK (
                confidence IS NULL OR confidence BETWEEN 0 AND 100
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_compatibilities');
        Schema::dropIfExists('product_use_cases');
        Schema::dropIfExists('use_cases');
        Schema::dropIfExists('bundle_components');

        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropColumn('bundle_kind');
        });

        Schema::dropIfExists('product_relationships');
        Schema::dropIfExists('product_content_sections');
    }
};

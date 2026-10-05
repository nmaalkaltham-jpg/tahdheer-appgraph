<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ السمات الكونيّ — المرحلة ٠١٣ من التفويض (§٤٣ §٤٤ §٥٠).
 *
 * القائم اليوم `product_facts`: مفتاحٌ نصّيّ و`int_value`/`text_value`. لا نوع
 * بيانات، ولا وحدة قياس، ولا قاعدة تحقّق، ولا أعلام بحثٍ أو فرز أو مقارنة، ولا
 * نطاق فئة. أي أنّ «١٢٠ صفحة» و«A4» و«قابل للتحرير» تُخزَّن جميعها كنصٍّ لا
 * يُفلتَر ولا يُقارَن. وبجانبه ثلاثة أعمدة مخبوزة في `products` — `resource_type`
 * و`format` و`editable` — وهي سماتٌ صار لها جدول (§٤٢).
 *
 * هنا تُعرَّف السمة مرّةً واحدة بنوعها ووحدتها وسلوكها، ثمّ تُسند قيمةً
 * **مكتوبة النوع**: عمودٌ لكلّ نوع، وقيدٌ يمنع ملء أكثر من واحد. فالرقم يبقى
 * رقمًا يُفرز ويُقارن، والوحدة تبقى عمودًا لا لاحقةً داخل نصّ (§٥٠).
 *
 * إضافيّة بالكامل: `product_facts` باقٍ ويعمل حتّى يثبت التكافؤ.
 */
return new class extends Migration
{
    /** §٤٤ — أنواع البيانات المدعومة. */
    private const DATA_TYPES = [
        'TEXT', 'LONG_TEXT', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'DATE', 'DATETIME',
        'ENUM', 'MULTI_ENUM', 'MEASUREMENT', 'MONEY', 'URL', 'REFERENCE',
        'ENTITY_REFERENCE', 'JSON_STRUCTURED',
    ];

    /** §٥٩ — مرتبة الحقيقة: من أين جاءت القيمة. */
    private const SOURCES = [
        'VERIFIED_HUMAN', 'AUTHORITATIVE_SOURCE', 'CANONICAL_TAXONOMY', 'VERIFIED_SYSTEM',
        'HIGH_CONFIDENCE_EXTRACTION', 'OCR_CANDIDATE', 'AI_SUGGESTION', 'BEHAVIORAL_SIGNAL',
    ];

    public function up(): void
    {
        /* §٤٣ — تعريف السمة: مرّةً واحدة، بسلوكها كاملًا. */
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description', 1000)->nullable();

            $table->enum('data_type', self::DATA_TYPES);
            $table->string('unit_type')->nullable();   // length|mass|count|duration|resolution…
            $table->json('allowed_values')->nullable(); // ENUM و MULTI_ENUM
            $table->json('validation_rule')->nullable();
            $table->json('category_scope')->nullable(); // فارغ = كلّ الفئات

            $table->boolean('required')->default(false);
            $table->boolean('variant_defining')->default(false); // §٤٢
            $table->boolean('searchable')->default(false);
            $table->boolean('filterable')->default(false);
            $table->boolean('facetable')->default(false);
            $table->boolean('sortable')->default(false);
            $table->boolean('comparison_enabled')->default(false);
            $table->boolean('seo_exposable')->default(false);

            $table->string('schema_mapping')->nullable(); // §٦٩ خرائط schema.org
            $table->string('feed_mapping')->nullable();   // §٩٧ خرائط التغذيات
            $table->unsignedSmallInteger('search_weight')->default(0); // §٦٤
            $table->enum('locale_strategy', ['SHARED', 'TRANSLATABLE'])->default('SHARED');
            $table->timestamps();

            $table->index(['filterable', 'facetable']);
        });

        /* §٤٤ §٥٠ — القيمة مكتوبة النوع، والوحدة عمودٌ مستقلّ. */
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_definition_id')->constrained()->cascadeOnDelete();

            /* صاحب القيمة: مجموعةٌ أو صيغة، واحدةٌ منهما حصرًا (قيدٌ أدناه).
               عمودان حقيقيّان لا تعدّديّةٌ نصّيّة — فيبقى المفتاح الأجنبيّ
               والحذف المتتالي عاملَين. */
            $table->foreignId('product_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('text_value')->nullable();
            $table->text('long_text_value')->nullable();
            $table->bigInteger('int_value')->nullable();
            $table->decimal('decimal_value', 20, 6)->nullable();
            $table->boolean('bool_value')->nullable();
            $table->date('date_value')->nullable();
            $table->timestamp('datetime_value')->nullable();
            $table->json('json_value')->nullable();

            $table->string('unit')->nullable();        // §٥٠ mm|g|page|slide|minute…
            $table->char('currency', 3)->nullable();   // MONEY وحدها
            $table->string('locale', 12)->nullable();  // TRANSLATABLE وحدها

            /* §٥٨ §٥٩ — الأصل والثقة. قيمةٌ بلا أصلٍ لا تصير حقيقةً قانونيّة. */
            $table->enum('source', self::SOURCES)->default('VERIFIED_HUMAN');
            $table->unsignedTinyInteger('confidence')->nullable(); // ٠–١٠٠
            $table->string('extractor')->nullable();
            $table->string('model_version')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['product_group_id', 'attribute_definition_id']);
            $table->index(['product_variant_id', 'attribute_definition_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // صاحبٌ واحدٌ حصرًا: مجموعة أو صيغة، لا كلاهما ولا لا شيء.
            DB::statement('ALTER TABLE attribute_values ADD CONSTRAINT attribute_values_single_owner CHECK ((product_group_id IS NULL) <> (product_variant_id IS NULL))');

            // §٤٤ — عمود قيمةٍ واحدٌ مملوء. هذا ما يمنع عودة الـEAV النصّيّ.
            DB::statement('ALTER TABLE attribute_values ADD CONSTRAINT attribute_values_exactly_one_value CHECK ((
                  (CASE WHEN text_value      IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN long_text_value IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN int_value       IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN decimal_value   IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN bool_value      IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN date_value      IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN datetime_value  IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN json_value      IS NOT NULL THEN 1 ELSE 0 END)
            ) = 1)');

            // §٥٠ — القياس بلا وحدةٍ رقمٌ بلا معنى.
            DB::statement('ALTER TABLE attribute_values ADD CONSTRAINT attribute_values_confidence_range CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 100)');

            /* فريدٌ لكلّ (سمة · صاحب · لغة). فهارس جزئيّة لأنّ NULL في Postgres
               لا يساوي NULL، فقيد الفرادة العاديّ يسمح بالتكرار صامتًا. */
            DB::statement('CREATE UNIQUE INDEX attribute_values_group_unique ON attribute_values (attribute_definition_id, product_group_id, COALESCE(locale, \'\')) WHERE product_group_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX attribute_values_variant_unique ON attribute_values (attribute_definition_id, product_variant_id, COALESCE(locale, \'\')) WHERE product_variant_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attribute_definitions');
    }
};

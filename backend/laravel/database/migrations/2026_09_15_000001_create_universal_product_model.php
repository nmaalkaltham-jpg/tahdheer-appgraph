<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * النموذج الكونيّ للمنتج — المراحل ٠٠٩–٠١٢ من التفويض.
 *
 * جدول `products` الحالي يخلط الهويّة والتصنيف والمحتوى والسمات والتسليم في
 * صفٍّ واحد، وعمود `fulfillment_type` نصٌّ افتراضه `digital` وقيمته واحدة في
 * كلّ الصفوف الستّة والعشرين. أي أنّ التسليم مفتاحٌ لا استراتيجيّة، وهو ما
 * يمنع نمذجة اثني عشر نوعًا من ثلاثة عشر نوعًا يوجبها التفويض.
 *
 * هذه الهجرة **إضافيّة بالكامل**: لا تمسّ `products` ولا `product_prices`
 * ولا الطلبات الـ١٥٣ ولا الاستحقاقات الـ٢٧. النموذجان يتعايشان عبر جسر
 * `legacy_product_id`، على نمط توسيع–ترحيل–تقليص. التقليص مرحلةٌ لاحقة
 * لا تبدأ قبل أن يثبت التكافؤ السلوكيّ (§١١).
 *
 * الفصل الذي تفرضه البنية:
 *   ما هو المنتج    → product_groups.product_nature
 *   كيف يُباع       → product_offers.selling_model
 *   كيف يُسعَّر      → product_offers (مبالغ ودورة)
 *   كيف يُخزَّن      → product_offers.inventory_strategy
 *   كيف يُسلَّم      → offer_fulfillments (واحدة أو أكثر لكلّ عرض)
 *   كيف يُضرَّب      → tax_classes
 */
return new class extends Migration
{
    /** §٦ — طبيعة المنتج. لا تحدّد وحدها سلوك الدفع. */
    private const NATURES = ['DIGITAL', 'PHYSICAL', 'SERVICE', 'HYBRID', 'ACCESS_BASED', 'COMPOSITE'];

    /** §٧ — نموذج البيع، مستقلّ عن الطبيعة تمامًا. */
    private const SELLING_MODELS = [
        'ONE_TIME', 'SUBSCRIPTION', 'RECURRING', 'PREORDER', 'BACKORDER', 'RENTAL',
        'RESERVATION', 'BOOKING', 'QUOTE_REQUIRED', 'PAY_WHAT_YOU_WANT', 'FREE',
        'LICENSE_PURCHASE', 'MEMBERSHIP',
    ];

    /** §٢٨ — استراتيجيّة المخزون، مستقلّة عن طبيعة المنتج. */
    private const INVENTORY_STRATEGIES = [
        'UNLIMITED', 'TRACKED', 'LICENSE_POOL', 'SEAT_CAPACITY', 'TIME_CAPACITY',
        'MADE_TO_ORDER', 'PREORDER', 'BACKORDER', 'EXTERNAL',
    ];

    /** §٨ — استراتيجيّات التسليم. للعرض الواحد واحدةٌ أو أكثر. */
    private const FULFILLMENT_STRATEGIES = [
        'DIGITAL_DOWNLOAD', 'CLOUD_FILE_DELIVERY', 'GOOGLE_DRIVE_DELIVERY',
        'OBJECT_STORAGE_DELIVERY', 'EXTERNAL_SECURE_LINK', 'LICENSE_KEY',
        'ACCOUNT_ENTITLEMENT', 'SAAS_PROVISIONING', 'API_PROVISIONING', 'STREAMING_ACCESS',
        'PHYSICAL_SHIPMENT', 'LOCAL_PICKUP', 'STORE_PICKUP', 'COURIER_DELIVERY',
        'FREIGHT', 'DROPSHIP', 'APPOINTMENT', 'BOOKING', 'MANUAL_SERVICE',
    ];

    public function up(): void
    {
        /* §١٠٧ — صنف الضريبة مرجعٌ مستقلّ، لا تسمية فئةٍ في الواجهة. */
        Schema::create('tax_classes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('rate_bps'); // نقاط أساس: ١٥٪ = 1500
            $table->string('jurisdiction', 2)->default('SA');
            $table->timestamps();
        });

        /* §٤١ — المجموعة: ما يراه الزبون منتجًا واحدًا وتحته صيغ متعدّدة. */
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('short_desc', 1000)->nullable();
            $table->longText('description')->nullable();
            $table->enum('product_nature', self::NATURES);
            $table->enum('status', ['draft', 'published', 'retired'])->default('draft');

            /* §٤٠ — هويّات خارجيّة. تبقى فارغةً حتّى تُعرف؛ لا تُختلق أبدًا. */
            $table->string('brand')->nullable();
            $table->string('manufacturer')->nullable();

            /* §٤٥ — المنهج يبقى معرّفات كيانات لا نصًّا، كما في `products`. */
            $table->foreignId('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();

            /* جسر التوسيع–الترحيل–التقليص. يسقط في مرحلة التقليص وحدها. */
            $table->foreignId('legacy_product_id')->nullable()->unique()->constrained('products')->nullOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
            $table->index('product_nature');
        });

        /* §٤١ §٤٢ — الصيغة: وحدة المخزون والهويّة القابلة للبيع. */
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('slug')->unique();
            $table->string('title')->nullable(); // فارغٌ يعني: خذ عنوان المجموعة
            $table->unsignedInteger('position')->default(0);
            $table->enum('status', ['draft', 'published', 'retired'])->default('draft');

            /* §٤٠ — معرّفات تجاريّة. فارغة = غير معروفة؛ لا تُلفَّق (§١٥٨). */
            $table->string('gtin', 14)->nullable();
            $table->string('mpn')->nullable();
            $table->string('isbn', 17)->nullable();
            $table->string('barcode')->nullable();

            /* §٢٧ — أبعادٌ ماديّة بوحداتٍ صريحة. الوحدة عمودٌ لا لاحقةٌ في نصّ. */
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            $table->foreignId('legacy_product_id')->nullable()->unique()->constrained('products')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_group_id', 'position']);
        });

        /* §٤١ §٧ — العرض: كيف تُباع هذه الصيغة وبأيّ سعرٍ ومخزون. */
        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->enum('selling_model', self::SELLING_MODELS)->default('ONE_TIME');
            $table->enum('inventory_strategy', self::INVENTORY_STRATEGIES)->default('UNLIMITED');

            $table->integer('regular_minor');
            $table->integer('sale_minor')->nullable();
            $table->timestamp('sale_from')->nullable();
            $table->timestamp('sale_to')->nullable();
            $table->char('currency', 3)->default('SAR');

            /* §٢٣ — الاشتراك: الدورة بيانات العرض لا جدولٌ منفصل بعد. */
            $table->unsignedSmallInteger('billing_interval_count')->nullable();
            $table->enum('billing_interval_unit', ['DAY', 'WEEK', 'MONTH', 'YEAR'])->nullable();
            $table->unsignedSmallInteger('trial_days')->nullable();

            $table->foreignId('tax_class_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['draft', 'active', 'paused', 'retired'])->default('draft');
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_to')->nullable();
            $table->timestamps();
            $table->index(['product_variant_id', 'status']);
        });

        /* §٨ §١٠ — «التسليم استراتيجيّة لا قيمة منطقيّة»: صفٌّ لكلّ استراتيجيّة. */
        Schema::create('offer_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_offer_id')->constrained()->cascadeOnDelete();
            $table->enum('strategy', self::FULFILLMENT_STRATEGIES);
            $table->unsignedInteger('position')->default(0);
            $table->json('config')->nullable(); // إعداد المحوّل: لا منطق مخبوز في الدفع
            $table->timestamps();
            $table->unique(['product_offer_id', 'strategy']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // مرآة قيد `product_prices` القائم — التخفيض دون الأصل دائمًا.
            DB::statement('ALTER TABLE product_offers ADD CONSTRAINT product_offers_sale_below_regular CHECK (sale_minor IS NULL OR sale_minor < regular_minor)');
            DB::statement('ALTER TABLE product_offers ADD CONSTRAINT product_offers_regular_nonnegative CHECK (regular_minor >= 0)');
            // نافذة التخفيض لا تنقلب على نفسها.
            DB::statement('ALTER TABLE product_offers ADD CONSTRAINT product_offers_sale_window_ordered CHECK (sale_from IS NULL OR sale_to IS NULL OR sale_from < sale_to)');
            // §٢٣ — الاشتراك يوجب دورةً كاملة: العدد والوحدة معًا أو لا شيء.
            DB::statement('ALTER TABLE product_offers ADD CONSTRAINT product_offers_billing_interval_complete CHECK ((billing_interval_count IS NULL) = (billing_interval_unit IS NULL))');
            DB::statement("ALTER TABLE product_offers ADD CONSTRAINT product_offers_recurring_requires_interval CHECK (selling_model NOT IN ('SUBSCRIPTION','RECURRING','MEMBERSHIP') OR billing_interval_unit IS NOT NULL)");
            // §٧ — المجّانيّ ثمنه صفر، وغيره لا يكون صفرًا بالصدفة.
            DB::statement("ALTER TABLE product_offers ADD CONSTRAINT product_offers_free_is_zero CHECK (selling_model <> 'FREE' OR regular_minor = 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_fulfillments');
        Schema::dropIfExists('product_offers');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_groups');
        Schema::dropIfExists('tax_classes');
    }
};

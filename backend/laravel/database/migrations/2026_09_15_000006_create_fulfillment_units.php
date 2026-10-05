<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * وحدات التنفيذ ولقطة بند الطلب — المرحلتان ٠١٠ و٠٣٠ (§٩ §١٠ §٣٤ §١٠١).
 *
 * اليوم: بندُ طلبٍ واحد ⇦ استحقاقٌ واحد، بلا شرط. `EntitlementService::
 * grantForOrder` يمنح لكلّ بندٍ استحقاقًا دون أن يسأل كيف يُسلَّم هذا البند.
 * وذلك صحيحٌ ما دام كلّ شيءٍ تنزيلًا رقميًّا — وهو حال الصفوف الستّة
 * والعشرين. لكنّه يجعل الطلب عاجزًا عن الانقسام: منتجٌ هجين فيه تنزيلٌ
 * وشحنة ينتج استحقاقًا واحدًا ولا شحنة، فيُقفل الطلب «مكتملًا» والصندوق
 * لم يُرسل.
 *
 * وحدة التنفيذ تفكّ هذا الارتباط: لكلّ استراتيجيّةٍ تحت البند وحدةٌ مستقلّة
 * بحالتها. فالطلب يُقفل حين تُقفل وحداته كلّها لا حين يُمنح استحقاق.
 *
 * ولقطة البند (§١٠١) تُضاف أعمدةً فارغة **ولا تُملأ للبنود القائمة**:
 * اشتقاقُها اليوم من المنتج اشتقاقٌ من حاضرٍ قد يكون تغيّر، وهو بعينه ما
 * تمنعه §١٠١ — «تعديلات المنتج لا تُعيد كتابة حقيقة الطلب». الفراغ يقول
 * «لم يُسجَّل»، والصفر كان سيقول «سُجّل صفرًا».
 */
return new class extends Migration
{
    /** §٨ — استراتيجيّات التسليم. MIXED_FULFILLMENT ليست وحدةً بل نتيجةَ طلبٍ ذي وحدات. */
    private const STRATEGIES = [
        'DIGITAL_DOWNLOAD', 'CLOUD_FILE_DELIVERY', 'GOOGLE_DRIVE_DELIVERY',
        'OBJECT_STORAGE_DELIVERY', 'EXTERNAL_SECURE_LINK', 'LICENSE_KEY',
        'ACCOUNT_ENTITLEMENT', 'SAAS_PROVISIONING', 'API_PROVISIONING', 'STREAMING_ACCESS',
        'PHYSICAL_SHIPMENT', 'LOCAL_PICKUP', 'STORE_PICKUP', 'COURIER_DELIVERY',
        'FREIGHT', 'DROPSHIP', 'APPOINTMENT', 'BOOKING', 'MANUAL_SERVICE',
    ];

    /** §١٠ — حالات وحدة التنفيذ. */
    private const STATES = [
        'PENDING', 'READY', 'PROCESSING', 'FULFILLED', 'PARTIALLY_FULFILLED',
        'FAILED', 'CANCELLED', 'REVOKED',
    ];

    public function up(): void
    {
        Schema::create('fulfillment_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();

            $table->enum('strategy', self::STRATEGIES);
            $table->enum('status', self::STATES)->default('PENDING');
            $table->unsignedInteger('sequence')->default(0);

            /* الرابط إلى ما نتج فعلًا. كلٌّ فارغٌ حتّى تُنفَّذ استراتيجيّته. */
            $table->foreignId('entitlement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_source_id')->nullable()->constrained()->nullOnDelete();

            /*
             * §٢١ — «التنفيذ يجب أن يكون خامل التكرار». المفتاح مشتقّ من
             * (البند، الاستراتيجيّة) لا من وقتٍ ولا عدّاد، فإعادةُ نداءِ
             * webhook لا تُنشئ شحنةً ثانية ولا تمنح استحقاقًا مكرّرًا.
             */
            $table->string('idempotency_key')->unique();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->string('pending_reason', 500)->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // استراتيجيّةٌ واحدة لا تتكرّر تحت بندٍ واحد.
            $table->unique(['order_item_id', 'strategy']);
            $table->index(['order_id', 'status']);
            $table->index(['status', 'strategy']);
        });

        /* §١٠١ — لقطة البند. كلّها فارغةٌ مسموحة: الفراغ «لم يُسجَّل». */
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->foreignId('product_offer_id')->nullable()->after('product_variant_id')->constrained()->nullOnDelete();
            $table->string('sku_snapshot')->nullable()->after('title_snapshot');
            $table->string('product_nature_snapshot')->nullable()->after('sku_snapshot');
            $table->string('selling_model_snapshot')->nullable()->after('product_nature_snapshot');
            $table->json('fulfillment_strategies_snapshot')->nullable()->after('selling_model_snapshot');
            $table->json('selected_options_snapshot')->nullable()->after('fulfillment_strategies_snapshot');
            /*
             * الكمّيّة فارغةٌ للقديم لا واحدًا: البنود الرقميّة كانت مقفلةً
             * على واحد في طبقة الخدمة، لكنّها لم تُسجَّل قطّ. كتابةُ ١ هنا
             * اختراعُ قياسٍ لم يُؤخذ.
             */
            $table->unsignedInteger('quantity')->nullable()->after('price_snapshot_minor');
            $table->integer('discount_minor')->nullable()->after('quantity');
            $table->integer('tax_minor')->nullable()->after('discount_minor');
        });

        if (DB::getDriverName() === 'pgsql') {
            // «نُفّذت» بلا وقتٍ لا يُدقَّق ولا يُحاسَب عليه.
            DB::statement("ALTER TABLE fulfillment_units ADD CONSTRAINT fulfillment_units_fulfilled_has_time CHECK (
                status <> 'FULFILLED' OR fulfilled_at IS NOT NULL
            )");

            // «فشلت» بلا سبب مكتوب تُعيد المشغّل إلى التخمين.
            DB::statement("ALTER TABLE fulfillment_units ADD CONSTRAINT fulfillment_units_failed_has_reason CHECK (
                status <> 'FAILED' OR last_error IS NOT NULL
            )");

            /*
             * وحدةٌ معلّقة بلا سببٍ مكتوب هي بالضبط ما يجعل طلبًا يقف أسابيع
             * بلا أن يعرف أحدٌ لماذا. الانتظار حالةٌ مشروعة؛ الصمت ليس.
             */
            DB::statement("ALTER TABLE fulfillment_units ADD CONSTRAINT fulfillment_units_pending_has_reason CHECK (
                status <> 'PENDING' OR pending_reason IS NOT NULL
            )");

            // الكمّيّة إن سُجّلت فهي موجبة — صفرٌ بندٌ لا وجود له.
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity IS NULL OR quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropConstrainedForeignId('product_offer_id');
            $table->dropColumn([
                'sku_snapshot', 'product_nature_snapshot', 'selling_model_snapshot',
                'fulfillment_strategies_snapshot', 'selected_options_snapshot',
                'quantity', 'discount_minor', 'tax_minor',
            ]);
        });

        Schema::dropIfExists('fulfillment_units');
    }
};

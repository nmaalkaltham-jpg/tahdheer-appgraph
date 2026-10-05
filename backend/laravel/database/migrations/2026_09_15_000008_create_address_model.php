<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نموذج العناوين — المرحلة ٠٣٢ (§٣٢ §١٠٦).
 *
 * الدفع صار يعرف متى يلزم عنوان شحن (`CheckoutRequirements`)، ولا موضع
 * لتخزينه: `orders` يحمل بريدًا وهاتفًا وحدهما. فالوعد قائمٌ والمكان غائب.
 *
 * العنوان **لقطةٌ على الطلب لا مرجع**: تعديلُ الزبون عنوانَه بعد الشحن لا
 * يجوز أن يُعيد كتابة وجهةِ شحنةٍ سُلّمت (§١٠١). ولذلك جدولان لا واحد —
 * دفتر عناوين الزبون، ونسخةٌ مجمّدة تُكتب على الطلب.
 *
 * وحقول العنوان اختياريّةٌ عمدًا إلّا القليل: البنية السعوديّة (المبنى·
 * الحيّ·الرمز الإضافي) لا تُطابق النموذج الغربيّ، وإلزامُ «الولاية» يجعل
 * عنوانًا صحيحًا مستحيلَ الإدخال.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* دفتر عناوين الزبون — قابلٌ للتعديل والحذف. */
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();            // «المنزل»، «العمل»
            $table->string('recipient_name');
            $table->string('phone');
            $table->char('country_code', 2)->default('SA');
            $table->string('region')->nullable();           // المنطقة الإداريّة
            $table->string('city');
            $table->string('district')->nullable();         // الحيّ — أساسيّ سعوديًّا
            $table->string('street')->nullable();
            $table->string('building_number', 10)->nullable();
            $table->string('secondary_number', 10)->nullable(); // الرقم الإضافي
            $table->string('postal_code', 12)->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
        });

        /*
         * لقطة العنوان على الطلب. لا مفتاح أجنبيّ إلى `addresses` بقصد:
         * حذفُ الزبون عنوانَه لا يجوز أن يُفرّغ وجهة شحنةٍ تاريخيّة.
         * `source_address_id` مرجعٌ ضعيف للتتبّع وحده، بلا قيد.
         */
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['SHIPPING', 'BILLING']);
            $table->unsignedBigInteger('source_address_id')->nullable();

            $table->string('recipient_name');
            $table->string('phone');
            $table->char('country_code', 2);
            $table->string('region')->nullable();
            $table->string('city');
            $table->string('district')->nullable();
            $table->string('street')->nullable();
            $table->string('building_number', 10)->nullable();
            $table->string('secondary_number', 10)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            // عنوان شحنٍ واحد وعنوان فوترةٍ واحد لكلّ طلب.
            $table->unique(['order_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // اسمُ مستلمٍ أو مدينةٌ فارغة تعني شحنةً لا تصل.
            DB::statement('ALTER TABLE addresses ADD CONSTRAINT addresses_recipient_present CHECK (length(btrim(recipient_name)) > 0 AND length(btrim(city)) > 0)');
            DB::statement('ALTER TABLE order_addresses ADD CONSTRAINT order_addresses_recipient_present CHECK (length(btrim(recipient_name)) > 0 AND length(btrim(city)) > 0)');
            // إحداثيّتان معًا أو لا شيء — واحدةٌ وحدها نقطةٌ لا وجود لها.
            DB::statement('ALTER TABLE addresses ADD CONSTRAINT addresses_coords_paired CHECK ((latitude IS NULL) = (longitude IS NULL))');
            DB::statement('ALTER TABLE order_addresses ADD CONSTRAINT order_addresses_coords_paired CHECK ((latitude IS NULL) = (longitude IS NULL))');
            /*
             * افتراضيٌّ واحد لكلّ زبون. فهرسٌ جزئيّ لا قيد: القيد العاديّ على
             * (customer_id, is_default) كان سيمنع أكثر من عنوانٍ غير افتراضيّ.
             */
            DB::statement('CREATE UNIQUE INDEX addresses_one_default_per_customer ON addresses (customer_id) WHERE is_default');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('addresses');
    }
};

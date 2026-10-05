<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طبقة اللغات: سجلّ اللغات وترجمات المنتجات.
 *
 * قرار بنيوي: **اللغة الافتراضية ليست ترجمة**. المحتوى العربي يسكن أعمدة
 * `products` نفسها، ولا يُنسَخ إلى صفّ ترجمة. النسخ يخلق مصدرَي حقيقة لنفس
 * النصّ، فيتحرّك أحدهما ويجمد الآخر ولا أحد يعرف أيّهما المعروض.
 *
 * `is_routable` عمود صادق لا زينة: اللغة قد تكون مفعّلة في اللوحة والواجهة لا
 * تخدم مسارها بعد. إعلان hreflang لصفحة تُعيد 404 عطبٌ حقيقي في الفهرسة، فيبقى
 * السجلّ يقول «غير مربوطة» حتى يُربط المسار فعلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locales', function (Blueprint $table) {
            $table->string('code', 12)->primary();
            $table->string('name_ar');
            $table->string('name_en');
            // وسم hreflang قد يخالف الرمز: ar ⇒ ar-SA.
            $table->string('hreflang', 20);
            $table->enum('direction', ['rtl', 'ltr'])->default('rtl');
            $table->string('url_prefix', 12)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_routable')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'position']);
        });

        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('locale_code', 12);
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('short_desc', 1000)->nullable();
            $table->longText('description')->nullable();
            $table->string('slug')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->boolean('is_indexable')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('locale_code')->references('code')->on('locales')->cascadeOnDelete();
            // ترجمة واحدة لكل منتج ولغة — لا نسختان تتنافسان.
            $table->unique(['product_id', 'locale_code']);
            // ولا رابطان متطابقان في اللغة نفسها.
            $table->unique(['locale_code', 'slug']);
            $table->index(['locale_code', 'status']);
        });

        /*
         * العربية افتراضية ومفعّلة وموصولة: المتجر يعمل بها اليوم.
         * الإنجليزية مسجَّلة وغير مفعّلة — الحقيقة الحالية، لا وعد.
         */
        $now = now();
        DB::table('locales')->insert([
            [
                'code' => 'ar', 'name_ar' => 'العربية', 'name_en' => 'Arabic',
                'hreflang' => 'ar-SA', 'direction' => 'rtl', 'url_prefix' => null,
                'is_default' => true, 'is_active' => true, 'is_routable' => true,
                'position' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'en', 'name_ar' => 'الإنجليزية', 'name_en' => 'English',
                'hreflang' => 'en', 'direction' => 'ltr', 'url_prefix' => 'en',
                'is_default' => false, 'is_active' => false, 'is_routable' => false,
                'position' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_translations');
        Schema::dropIfExists('locales');
    }
};

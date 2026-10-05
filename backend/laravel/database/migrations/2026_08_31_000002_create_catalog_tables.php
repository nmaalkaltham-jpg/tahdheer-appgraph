<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('short_desc', 1000)->nullable();
            $table->longText('description')->nullable();
            $table->string('resource_type')->nullable();
            $table->string('format')->nullable();
            $table->foreignId('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('editable', ['yes', 'no', 'unset'])->default('unset');
            $table->enum('status', ['draft', 'published', 'retired'])->default('draft');
            $table->foreignId('replacement_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('fulfillment_type')->default('digital');
            $table->timestamp('published_at')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });

        Schema::create('product_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('key'); // slides_count|pages_count|worksheets_count|activities_count|...
            $table->integer('int_value')->nullable();
            $table->string('text_value')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'key']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('regular_minor');
            $table->integer('sale_minor')->nullable();
            $table->timestamp('sale_from')->nullable();
            $table->timestamp('sale_to')->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->timestamps();
        });

        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('alt_ar')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });

        Schema::create('product_seo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('seo_title')->nullable();
            $table->string('seo_desc', 500)->nullable();
            $table->text('quick_answer')->nullable();
            $table->text('synonyms')->nullable();
            $table->string('canonical_override')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_facts ADD CONSTRAINT product_facts_int_value_nonnegative CHECK (int_value IS NULL OR int_value >= 0)');
            DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_sale_below_regular CHECK (sale_minor IS NULL OR sale_minor < regular_minor)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_seo');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_facts');
        Schema::dropIfExists('products');
    }
};

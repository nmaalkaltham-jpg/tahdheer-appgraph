<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // garage|external
            $table->string('provider_ref');
            $table->string('original_name');
            $table->string('customer_label')->nullable();
            $table->string('sha256', 64)->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime');
            $table->enum('visibility', ['public_sample', 'paid_private'])->default('paid_private');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
        });

        Schema::create('product_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('role')->default('main'); // main|companion|sample|teacher_guide|answer_key
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'asset_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_files');
        Schema::dropIfExists('assets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactional outbox — business state + outbox row commit in the SAME transaction.
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->string('correlation_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->enum('status', ['PENDING', 'PROCESSING', 'PROCESSED', 'FAILED', 'DEAD'])->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_retry_at']);
            $table->index('created_at');
        });

        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->string('raw_query', 500);
            $table->string('normalized_query', 500);
            $table->unsignedInteger('results_count')->default(0);
            $table->boolean('zero_result')->default(false);
            $table->foreignId('clicked_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('position')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('normalized_query');
            $table->index(['zero_result', 'created_at']);
        });

        Schema::create('redirect_rules', function (Blueprint $table) {
            $table->id();
            $table->string('from_path')->unique();
            $table->string('to_path');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
        });

        Schema::create('not_found_hits', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('referrer')->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('not_found_hits');
        Schema::dropIfExists('redirect_rules');
        Schema::dropIfExists('search_queries');
        Schema::dropIfExists('outbox_events');
    }
};

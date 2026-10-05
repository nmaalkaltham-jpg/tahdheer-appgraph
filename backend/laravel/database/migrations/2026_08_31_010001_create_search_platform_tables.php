<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Synonym engine (MIP §6.5): seeded verbatim from the legacy theme,
        // extended later by admin (manual) and the nightly learner (learned).
        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('term');
            $table->string('synonym');
            $table->enum('direction', ['one_way', 'two_way'])->default('one_way');
            $table->string('scope')->nullable(); // null = global; else e.g. subject:العلوم
            $table->enum('state', ['active', 'pending', 'negative'])->default('active');
            $table->enum('source', ['manual', 'learned', 'seed'])->default('manual');
            $table->unsignedInteger('evidence_count')->default(0);
            $table->timestamps();
            $table->unique(['term', 'synonym']);
            $table->index(['state', 'term']);
        });

        // Versioned-index bookkeeping (MIP §6.1): products_v{generation};
        // swap = update this row, rollback = decrement generation.
        Schema::create('search_index_state', function (Blueprint $table) {
            $table->id();
            $table->string('engine')->default('meilisearch');
            $table->string('index_name');
            $table->unsignedInteger('generation')->default(0);
            $table->timestamp('last_built_at')->nullable();
            $table->string('status')->default('idle'); // idle|building|ready|failed
            $table->timestamps();
            $table->unique('engine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index_state');
        Schema::dropIfExists('search_synonyms');
    }
};

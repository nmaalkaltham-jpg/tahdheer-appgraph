<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('icon')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('kinds', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('curriculum_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type'); // stage|grade|subject|semester|kind
            $table->unsignedBigInteger('entity_id');
            $table->string('alias');
            $table->string('normalized_alias');
            $table->timestamps();
            $table->unique(['entity_type', 'entity_id', 'normalized_alias'], 'curriculum_aliases_entity_norm_unique');
            $table->index('normalized_alias');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_aliases');
        Schema::dropIfExists('kinds');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('stages');
    }
};

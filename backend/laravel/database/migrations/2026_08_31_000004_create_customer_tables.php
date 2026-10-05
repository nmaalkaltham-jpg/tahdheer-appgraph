<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passwordless by design: no password column, ever.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('locale', 10)->default('ar');
            $table->boolean('marketing_consent')->default(false);
            $table->timestamps();
        });

        Schema::create('auth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // google|apple|magic
            $table->string('provider_subject');
            $table->string('verified_email')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_subject']);
        });

        Schema::create('magic_links', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('email');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_links');
        Schema::dropIfExists('auth_identities');
        Schema::dropIfExists('customers');
    }
};

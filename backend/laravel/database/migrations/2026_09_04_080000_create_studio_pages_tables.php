<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Studio Phase 1 (SPEC §66, §79): studio_pages + studio_page_versions.
 *
 * المستند شجرة JSONB واحدة (قائمة أقسام مسطّحة في المرحلة الأولى): المحرر يحمّل
 * الصفحة كاملة، والحفظ التلقائي يكتبها كاملة، والمتجر يصيّرها كاملة — لا استعلام
 * يعنون عقدة مفردة (نفس حجّة navigation_menus). Draft وPublished عمودان منفصلان:
 * المسار العام يقرأ published_document فقط فلا يمكن بنيويًا تسريب مسودة (SPEC §67؛
 * نفس حجّة design_components). نفس شكل design_components عمدًا — لم يُبنَ نظام نشر ثانٍ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_key', 120);                 // storefront slug: /pages/{page_key}
            $table->string('locale', 10)->default('ar');
            $table->string('name', 160);

            $table->json('draft_document');                  // autosave target
            $table->json('published_document')->nullable();  // the ONLY column public reads touch
            $table->unsignedSmallInteger('schema_version')->default(1);   // SPEC §65

            $table->unsignedInteger('version')->default(1);              // optimistic counter (SPEC §72)
            $table->unsignedInteger('published_version')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['locale', 'page_key']);
            $table->index('published_at');
        });

        Schema::create('studio_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('studio_pages')->cascadeOnDelete();
            $table->unsignedInteger('version');              // == studio_pages.version at write time
            $table->string('kind', 20);                      // autosave | checkpoint | publish (SPEC §38)
            $table->string('label', 160)->nullable();        // checkpoint name
            $table->json('document');                        // full snapshot, never a diff
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['page_id', 'version']);
            $table->index(['page_id', 'kind', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // لا نصفَ نشرٍ في القاعدة: الرقم والمستند يوجدان معًا أو لا يوجدان.
            DB::statement('ALTER TABLE studio_pages ADD CONSTRAINT studio_pages_publish_pair
                CHECK ((published_version IS NULL) = (published_document IS NULL))');
            DB::statement("ALTER TABLE studio_page_versions ADD CONSTRAINT studio_page_versions_kind
                CHECK (kind IN ('autosave','checkpoint','publish'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_page_versions');
        Schema::dropIfExists('studio_pages');
    }
};

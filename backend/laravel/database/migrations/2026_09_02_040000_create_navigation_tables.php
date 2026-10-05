<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قوائم التنقّل.
 *
 * الشجرة تُخزَّن وثيقةً واحدة في `draft_nodes` / `published_nodes` لا صفًّا لكل
 * عنصر. السبب: القائمة تُقرأ كاملةً دائمًا وتُكتب كاملةً دائمًا، ولا استعلام
 * واحد في المنتج يسأل عن عنصر بمفرده. جدول أبناء لشجرة تُقرأ كاملةً يضيف
 * انضمامات ومعاملات إعادة ترتيب مقابل صفر قدرة.
 *
 * `location` هو ما يربط القائمة بمكانها في المتجر (header / footer). فريد،
 * فلا قائمتان منشورتان تتنازعان الترويسة نفسها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_menus', function (Blueprint $table) {
            $table->id();
            $table->string('location', 40)->unique();
            $table->string('name', 160);

            $table->json('draft_nodes');
            $table->json('published_nodes')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('published_version')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            // نصف نشرٍ مستحيل: النسخة المنشورة والعقد المنشورة تُوجدان معًا.
            DB::statement('ALTER TABLE navigation_menus ADD CONSTRAINT navigation_menus_publish_pair
                CHECK ((published_version IS NULL) = (published_nodes IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_menus');
    }
};

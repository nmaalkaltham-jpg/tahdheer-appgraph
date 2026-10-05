<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ العناوين — الهويّة الدائمة للعنوان العامّ، وتاريخُه.
 *
 * ─── العيب ───
 *
 * الرابط كان يُكتب يدويًّا في عمود `slug` ولا يُذكر تغييرُه في أيّ مكان:
 * مشغّلٌ يصحّح رابط منتجٍ مُفهرَس فيصير الرابط القديم ٤٠٤ صامتًا عند كلّ
 * زائرٍ ومحرّك بحث، ولا قاعدة تحويلٍ تُكتب ما لم يتذكّر أحد. والتفرّد كان
 * لكلّ جدولٍ على حدة، فلا شيء يمنع منتجًا وصفحةً من تقاسم المسار نفسه.
 *
 * ─── الجدولان ───
 *
 * `url_registry`: صفٌّ لكلّ كيانٍ ولغةٍ ومتجر — الهويّة `entity_type +
 * entity_id`، والرابط تابعٌ لها لا العكس. التفرّد **جزئيّ**: على الحالات
 * الحيّة وحدها، فالمؤرشف والمحذوف لا يحجزان مسارًا لكنّهما يبقيان تاريخًا.
 * والمفاتيح `slug_key`/`path_key` مطويّةُ الحالة لتفرّدٍ لا يفرّق بين
 * `Ab` و`ab`.
 *
 * `url_history`: صفٌّ لكلّ تحوّلٍ في الرابط، لا يُحذف. المسار السابق يبقى
 * قابلًا للتوجيه ما دام صفّه هنا، وقاعدة التحويل المولَّدة تُذكر بمعرّفها.
 *
 * الطوابع بلا منطقة زمنيّة كبقيّة المستودع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('url_registry', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('store', 40)->default('default');
            $table->string('locale', 12)->default('ar');
            $table->string('slug', 255);
            $table->string('slug_key', 255);
            $table->string('path', 512);
            $table->string('path_key', 512);
            $table->string('canonical_url', 1024)->nullable();
            $table->string('route_owner', 40);
            $table->string('status', 20)->default('draft');
            $table->boolean('indexable')->default(true);
            $table->boolean('reserved')->default(false);
            $table->unsignedBigInteger('redirect_rule_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['status']);
            $table->index(['path_key']);
        });

        /*
         * التفرّد على الحيّ وحده — قيدٌ جزئيّ في القاعدة لا فحصٌ قبل الإدراج:
         * الفحص سباقٌ بعينه، والقيد هو ما يلتقط الكتابتين المتزامنتين.
         */
        $active = "status IN ('draft','reserved','published','locked')";
        DB::statement("CREATE UNIQUE INDEX url_registry_active_path ON url_registry (store, locale, path_key) WHERE {$active}");
        DB::statement("CREATE UNIQUE INDEX url_registry_active_slug ON url_registry (store, locale, entity_type, slug_key) WHERE {$active}");
        DB::statement("CREATE UNIQUE INDEX url_registry_active_entity ON url_registry (store, locale, entity_type, entity_id) WHERE {$active}");

        Schema::create('url_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registry_id');
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('store', 40)->default('default');
            $table->string('locale', 12)->default('ar');
            $table->string('previous_slug', 255)->nullable();
            $table->string('previous_path', 512)->nullable();
            $table->string('new_slug', 255)->nullable();
            $table->string('new_path', 512)->nullable();
            $table->string('event', 40);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('change_source', 40)->default('admin');
            $table->unsignedBigInteger('redirect_rule_id')->nullable();
            $table->string('approval_state', 20)->default('auto');
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at');

            $table->index(['registry_id']);
            $table->index(['previous_path']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('url_history');
        Schema::dropIfExists('url_registry');
    }
};

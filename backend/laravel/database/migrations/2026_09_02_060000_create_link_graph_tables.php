<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رسم الروابط الداخلية — الطبقة التي تجعل «هل هذه الصفحة قابلة للاكتشاف؟»
 * سؤالًا له جواب.
 *
 * `crawled_pages` عقدة لكل عنوان زاره الزاحف: حالته وعمقه من الرئيسية.
 * `internal_links` حافة بين عقدتين بنصّ المرساة وموضعها.
 *
 * لماذا جدول حواف لا عمود «روابط» في الصفحة: كل سؤال هنا سؤال عن **العلاقة**
 * — من يشير إلى هذه الصفحة؟ كم بابًا يقود إليها؟ ما نصّ المرساة؟ — وهي أسئلة
 * لا يجيبها إلا صفٌّ لكل حافة.
 *
 * الزحف مشتقّ لا مصدر: يُبنى من الصفر في كل جولة، ولا يقرّر شيئًا عن النشر
 * أو الفهرسة. هو مرصد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('links')->default(0);
            $table->unsignedInteger('broken')->default(0);
            $table->unsignedInteger('orphans')->default(0);
            $table->unsignedInteger('max_depth')->default(0);
            $table->text('failure')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
        });

        Schema::create('crawled_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->string('path', 500);
            $table->unsignedSmallInteger('status')->nullable();
            /*
             * عمق النقر: أقصر مسافة من الرئيسية بالروابط. `null` يعني «لم
             * يُوصَل إليه» — وهو بالضبط تعريف الصفحة اليتيمة، لا صفر مضلّل.
             */
            $table->unsignedSmallInteger('depth')->nullable();
            $table->unsignedInteger('inbound')->default(0);
            $table->unsignedInteger('outbound')->default(0);
            $table->string('title', 300)->nullable();
            $table->string('canonical', 500)->nullable();
            $table->string('robots', 120)->nullable();
            $table->string('redirect_to', 500)->nullable();
            $table->string('discovered_from', 20)->default('link');
            $table->timestamp('crawled_at')->nullable();

            $table->unique(['run_id', 'path']);
            $table->index(['run_id', 'status']);
            $table->index(['run_id', 'depth']);
        });

        Schema::create('internal_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->string('from_path', 500);
            $table->string('to_path', 500);
            $table->string('anchor', 300)->nullable();
            // موضع الرابط: رابط في التذييل ليس كرابط في متن الصفحة.
            $table->string('placement', 20)->default('body');
            $table->boolean('nofollow')->default(false);

            $table->index(['run_id', 'to_path']);
            $table->index(['run_id', 'from_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_links');
        Schema::dropIfExists('crawled_pages');
        Schema::dropIfExists('crawl_runs');
    }
};

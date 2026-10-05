<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الطبقة التشغيلية للتنقّل: الإصدارات وسجلّ عمليات النشر.
 *
 * `navigation_revisions` هو مخزن اللقطات: كل حفظ مسوّدة يكتب إصدارًا بشجرته
 * وبصمته. النشر لا «يأخذ لقطة» بفعل منفصل — يسجّل أيّ إصدار كان منشورًا قبله،
 * فالتراجع يستعيد شجرةً محفوظة فعلًا لا إعادةَ بناءٍ من ذاكرة.
 *
 * `navigation_publish_operations` سجلّ دائم يجيب سؤالًا لا تجيبه الحالة وحدها:
 * «ماذا حدث في المحاولة الفلانية، وأين توقّفت؟». بدونه يفقد انقطاعُ الشبكة
 * بعد الالتزام معناه: المتصفّح يعيد المحاولة والخادم لا يعرف أنه أتمّ العمل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('navigation_menus')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('nodes');
            // بصمة التمثيل المعياري — بها يُتحقّق التراجع لا بالثقة.
            $table->char('fingerprint', 64);
            $table->unsignedInteger('node_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['menu_id', 'revision']);
            $table->index(['menu_id', 'created_at']);
        });

        Schema::create('navigation_publish_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            /*
             * مفتاح التكرار فريد: إعادة إرسال الطلب نفسه بعد انقطاع تُعيد
             * العملية القائمة ولا تُنشئ نشرًا ثانيًا.
             */
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('menu_id')->constrained('navigation_menus')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('admin_users')->nullOnDelete();

            $table->unsignedInteger('base_revision')->nullable();
            $table->unsignedInteger('target_revision');
            // الإصدار الذي كان منشورًا قبل هذه العملية — وجهة التراجع.
            $table->unsignedInteger('snapshot_revision')->nullable();

            $table->string('status', 40);
            $table->string('current_step', 40);
            $table->string('failure_code', 60)->nullable();
            $table->text('failure_detail')->nullable();

            $table->char('verified_fingerprint', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->index(['menu_id', 'started_at']);
            $table->index('status');
        });

        // موقع القائمة: يتّسع لقائمة الجوّال المستقلّة عن الترويسة.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE navigation_menus ALTER COLUMN location TYPE varchar(40)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_publish_operations');
        Schema::dropIfExists('navigation_revisions');
    }
};

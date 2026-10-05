<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أحداث القياس الأولى‑الطرف — إحياءُ جدولٍ فقد هجرته.
 *
 * ─── لماذا يُهاجَر جدولٌ موجود ───
 *
 * `analytics_events` قائمٌ في قاعدة التطوير بستّةٍ وخمسين صفًّا وبلا هجرة:
 * حُذفت شيفرته عند دمج المستودع وبقي الجدول يتيمًا، فسُجِّل في
 * `scripts/known-schema-residue.json`. ومعنى ذلك أنّ نشرًا نظيفًا لا يملكه
 * أصلًا — فكلّ ما يُبنى عليه يعمل في التطوير ويختفي في الإنتاج.
 *
 * والسابقة `refunds.notified_at`: اسمٌ جديد للهجرة لا إحياءٌ للاسم القديم
 * (وإلّا تخطّاها التطوير إلى الأبد فاختفى أيّ تصحيحٍ لاحق)، وحارسُ وجودٍ
 * يجعلها بلا أثرٍ حيث الجدول قائم.
 *
 * ─── والشكل منقولٌ عمودًا بعمود ───
 *
 * `char(64)` لا `varchar`، و`json` لا `jsonb`، و`integer` لا `bigint` للقيمة.
 * فحصُ الانحراف يقارن الأسماء وحدها، فاختلاف النوع يمرّ صامتًا ثمّ يُنتج
 * بيئتين تتصرّفان تصرّفين.
 *
 * ─── والوعد المنشور يصير قيدًا ───
 *
 * سياسة الخصوصيّة المنشورة تقول: «تفاصيل أحداث القياس تُحذف بعد تسعين يومًا».
 * وجدول مدد الاحتفاظ كان **فارغًا** — أي أنّ الوعد بلا منفّذ. هذه الهجرة تكتب
 * السياسة صفًّا، والأمر المجدول ينفّذها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('analytics_events')) {
            Schema::create('analytics_events', function (Blueprint $table): void {
                $table->id();
                $table->timestamp('occurred_at');
                $table->string('event_type', 40);

                /*
                 * المعرّفان يُكتبان بموافقةٍ وحدها. وبلا موافقة يبقيان فارغين
                 * ويبقى الصفّ نافعًا: المسار ونوع الحدث والجهاز بلا رابطٍ
                 * شخصيّ — وهو الخطّ الذي ترسمه السياسة المنشورة نفسها.
                 */
                $table->char('visitor_hash', 64)->nullable();
                $table->char('session_hash', 64)->nullable();

                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->integer('value_minor')->nullable();
                $table->string('path', 200)->nullable();
                /*
                 * المضيف وحده لا الرابط كاملًا: الرابط الكامل يحمل وسائط بحثٍ
                 * قد تُسرّب ما كتبه الزائر في موقعٍ آخر.
                 */
                $table->string('referrer_host', 120)->nullable();
                $table->string('device', 10)->nullable();
                $table->boolean('is_logged_in')->default(false);
                $table->json('meta')->nullable();

                /*
                 * مفتاح إزالة التكرار: إعادة إرسالٍ بعد انقطاع شبكةٍ لا تعدّ
                 * مشاهدةً ثانية. فريدٌ وقابلٌ للإفراغ — حدثٌ بلا مفتاح يُقبل.
                 */
                $table->string('dedup_key', 120)->nullable()->unique();
                $table->timestamps();

                $table->index('occurred_at');
                $table->index(['event_type', 'occurred_at']);
                $table->index(['product_id', 'event_type', 'occurred_at']);
            });
        }

        /*
         * فهرسٌ للزائر — لم يكن في الشكل القديم، وبلا فهرسٍ عليه يصير كلّ
         * سؤال «كم زائرًا فريدًا» مسحًا كاملًا للجدول.
         */
        if (! $this->hasIndex('analytics_events', 'analytics_events_visitor_hash_occurred_at_index')) {
            Schema::table('analytics_events', function (Blueprint $table): void {
                $table->index(['visitor_hash', 'occurred_at'], 'analytics_events_visitor_hash_occurred_at_index');
            });
        }

        /*
         * ─── الوعد المنشور يصير صفًّا ───
         *
         * تسعون يومًا للتفاصيل كما تقول السياسة حرفيًّا. والمجمَّع بلا معرّف
         * يبقى أطول — وهو ما تجيزه السياسة نصًّا، فيُكتب صفًّا ثانيًا بدل أن
         * يُترك لتقدير من يكتب الاستعلام لاحقًا.
         */
        $now = now();

        foreach ([
            [
                'data_class' => 'analytics_events',
                'name' => 'تفاصيل أحداث القياس',
                'retain_days' => 90,
                'on_expiry' => 'DELETE',
                'legal_basis' => 'سياسة الخصوصيّة المنشورة تَعِد بحذف تفاصيل أحداث القياس بعد تسعين يومًا. '
                    .'المصلحة المشروعة في قياس أداء المتجر لا تبرّر حفظ صفٍّ مرتبطٍ بزائرٍ بعد انقضاء غرضه.',
            ],
            [
                'data_class' => 'analytics_aggregates',
                'name' => 'ملخّصات القياس المجمَّعة',
                'retain_days' => 730,
                'on_expiry' => 'ARCHIVE',
                'legal_basis' => 'ملخّصاتٌ بلا أيّ معرّف — لا زائر ولا جلسة ولا طلب. '
                    .'تُحفظ سنتين للمقارنة السنويّة كما تنصّ السياسة المنشورة، ولا تُعيد التعريف بشخص.',
            ],
        ] as $policy) {
            DB::table('retention_policies')->updateOrInsert(
                ['data_class' => $policy['data_class']],
                $policy + ['active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('retention_policies')->whereIn('data_class', ['analytics_events', 'analytics_aggregates'])->delete();

        /*
         * الجدول لا يُسقَط: كان قائمًا قبل هذه الهجرة في التطوير، وإسقاطُه
         * عند التراجع يمحو بياناتٍ لم تُنشئها هي. والفهرس وحده يُزال.
         */
        if ($this->hasIndex('analytics_events', 'analytics_events_visitor_hash_occurred_at_index')) {
            Schema::table('analytics_events', function (Blueprint $table): void {
                $table->dropIndex('analytics_events_visitor_hash_occurred_at_index');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        if (! Schema::hasTable($table) || DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return DB::table('pg_indexes')->where('tablename', $table)->where('indexname', $index)->exists();
    }
};

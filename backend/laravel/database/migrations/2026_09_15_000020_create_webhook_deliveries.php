<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ التسليمات الواردة من مزوّدي الدفع.
 *
 * ─── العيب الذي يصلحه ───
 *
 * كان الخطّاف يُتحقَّق من توقيعه ثمّ يُعالَج فورًا، ولا يُحفظ. ونتيجة ذلك
 * ثلاث خسائر صامتة:
 *
 * ١ — **فشلُ المعالجة يُفقد الحدث.** المزوّد يعيد المحاولة مرّاتٍ معدودة ثمّ
 *     يستسلم. بعدها لا يبقى أثرٌ لأنّ دفعةً وقعت — الزبون دفع والمتجر لا
 *     يعلم، ولا سبيل إلى إصلاحٍ لأنّ الحمولة نفسها ضاعت.
 *
 * ٢ — **لا إعادة تشغيل.** عطلٌ عابر عندنا لحظةَ الوصول كان يعني تدخّلًا
 *     يدويًّا في قاعدة البيانات، لا زرًّا يُعيد الحدث كما وصل.
 *
 * ٣ — **لا تدقيق.** «هل وصلنا هذا الحدث أصلًا؟» سؤالٌ يُطرح في كلّ نزاعٍ
 *     مع مزوّد، ولم يكن له جواب.
 *
 * ─── لماذا الحفظ قبل المعالجة ───
 *
 * الترتيب ملزم: يُحفظ الوارد أوّلًا ثمّ يُعالَج. وإلّا فكلّ عطلٍ بين
 * الوصول والحفظ يخسر الحدث — وهو ما كان يقع.
 */
return new class extends Migration
{
    private const STATES = ['RECEIVED', 'PROCESSED', 'IGNORED', 'UNMATCHED', 'FAILED'];

    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);

            /*
             * مفتاح إزالة التكرار: هويّة الحدث عند المزوّد. المزوّدون يعيدون
             * الإرسال بالتصميم — عشر مرّاتٍ لحدثٍ واحد أمرٌ عاديّ — ومعالجةُ
             * كلٍّ منها من جديد هي تنفيذٌ مكرّر بلبوسٍ آخر.
             */
            $table->string('dedupe_key', 191);
            $table->string('external_event_id', 191)->nullable();

            $table->string('charge_id', 191)->nullable();
            $table->string('order_number', 64)->nullable();

            $table->boolean('signature_verified')->default(false);

            /*
             * هل أعلن المزوّد نجاح الدفع؟ يُحفظ ولا يُشتقّ لاحقًا: إعادة
             * التشغيل تُعيد بناء الحدث من هذا الصفّ، واشتقاقُ المعنى من
             * الحالة يجعل إعادةَ تشغيلٍ تعالج شيئًا غير الذي وصل.
             */
            $table->boolean('succeeded')->default(false);
            $table->enum('status', self::STATES)->default('RECEIVED');

            /* الحمولة كما وصلت. هي ما يُعاد تشغيله، فلا تُطبَّع ولا تُختصر. */
            $table->json('payload');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('replay_count')->default(0);
            $table->string('last_error', 1000)->nullable();

            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'dedupe_key']);
            $table->index(['provider', 'status']);
            $table->index('order_number');
            $table->index('received_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * ما عولج له وقتُ معالجة. غيابُه مع حالةٍ منتهية يعني سجلًّا يقول
         * «تمّ» ولا يقول متى — وهو بالضبط ما يُبطل قيمته في النزاع.
         */
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_processed_has_time CHECK (
            status NOT IN ('PROCESSED', 'IGNORED', 'UNMATCHED') OR processed_at IS NOT NULL
        )");

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_failure_explained CHECK (
            status <> 'FAILED' OR last_error IS NOT NULL
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};

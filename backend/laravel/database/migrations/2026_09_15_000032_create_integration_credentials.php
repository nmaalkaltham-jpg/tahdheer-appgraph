<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * اعتمادات التكامل — مفاتيح المزوّدين، مُعمّاةً في قاعدة البيانات.
 *
 * ─── لماذا جدولٌ لا ملفّ بيئة ───
 *
 * كانت المفاتيح كلّها في `.env` وحده: لا يراها المالك، ولا يعرف أيّها
 * مضبوط، ولا يستطيع تدويرها بلا وصولٍ إلى الخادم. فكلّ تغييرٍ يمرّ بمهندس،
 * ومفتاحٌ مسرَّب يبقى صالحًا حتّى يفرغ ذلك المهندس.
 *
 * ─── لماذا عمودان لا عمود ───
 *
 * `merchant_id` و`pk_live_…` ليسا سرًّا: الأوّل معرّف حساب والثاني يُرسَل إلى
 * متصفّح كلّ زبون. وتعميتُهما يمنع المالك من قراءة ما يحقّ له قراءته ويجعل
 * كلّ حقلٍ يبدو خطيرًا — فيتوقّف عن قراءة التحذيرات. السرّ وحده يُعمّى،
 * وغيرُه يُخزَّن ظاهرًا ويُعرض كاملًا.
 *
 * ─── لماذا البصمة ───
 *
 * `fingerprint` تجيب سؤالًا يتكرّر: «هل هذا المفتاح هو نفسه الذي في
 * الخادم؟» — بلا كشف أيٍّ منهما. وهي `sha256` للقيمة، تُقارَن ولا تُعكس.
 *
 * ─── ولماذا لا يُحذف المسحوب ───
 *
 * السحب يُسجَّل ولا يُمحى: «متى بطل هذا المفتاح ومن أبطله؟» سؤالٌ يُسأل بعد
 * حادثة، وصفٌّ محذوف لا يجيب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 64);
            /* بيئتان لا أكثر: الخلط بينهما يُحصّل مالًا حقيقيًّا في اختبار. */
            $table->string('environment', 8);
            $table->string('field', 64);

            $table->boolean('is_secret')->default(false);

            /* القيمة الظاهرة — للحقول غير السرّيّة وحدها. */
            $table->text('value_public')->nullable();
            /* النصّ المعمّى — `Crypt` بمفتاح التطبيق. لا يُرَدّ عبر الشبكة أبدًا. */
            $table->text('value_encrypted')->nullable();
            /* ذيلٌ مقنَّع للعرض: «sk_live_••••BAM». يكفي للتمييز ولا يكفي للاستعمال. */
            $table->string('preview', 64)->nullable();
            /* بصمة القيمة — تُقارَن ولا تُعكس. */
            $table->char('fingerprint', 64)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            /*
             * حقلٌ واحد فعّال لكلّ (مزوّد · بيئة · حقل).
             *
             * جزئيٌّ على غير المسحوب: التدوير يسحب القديم ويُدخل الجديد،
             * فيبقى التاريخ كاملًا بلا أن يتصادم مع الفعّال.
             */
            $table->unique(['provider', 'environment', 'field'], 'integration_credentials_active_unique')
                ->deferrable(false);

            $table->index(['provider', 'environment']);
        });

        /*
         * القيود في المحرّك لا في التطبيق.
         *
         * `is_secret` يقرّر أيّ عمودٍ يحمل القيمة، ومستدعٍ يكتب الاثنين معًا
         * — أو لا يكتب أيًّا منهما — يترك صفًّا لا معنى له. الفحص يمنعه قبل
         * أن يُكتب، لا بعد أن يُقرأ.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE integration_credentials
              ADD CONSTRAINT integration_credentials_environment_check
              CHECK (environment IN ('test', 'live'))
        SQL);

        /*
                 * والمسحوب مُستثنى: السحب يمحو القيمة ويُبقي الصفّ للتاريخ، فصفٌّ
                 * مسحوبٌ بلا قيمةٍ حالةٌ صحيحة لا خرقٌ للقيد.
                 */
        DB::statement(<<<'SQL'
            ALTER TABLE integration_credentials
              ADD CONSTRAINT integration_credentials_storage_check
              CHECK (
                revoked_at IS NOT NULL
                OR (is_secret = true  AND value_encrypted IS NOT NULL AND value_public IS NULL)
                OR (is_secret = false AND value_public    IS NOT NULL AND value_encrypted IS NULL)
              )
        SQL);

        /* السرّ الفعّال يحمل معاينةً وبصمة دائمًا — بلاهما لا يُميَّز ولا يُقارَن. */
        DB::statement(<<<'SQL'
            ALTER TABLE integration_credentials
              ADD CONSTRAINT integration_credentials_secret_preview_check
              CHECK (
                revoked_at IS NOT NULL
                OR is_secret = false
                OR (preview IS NOT NULL AND fingerprint IS NOT NULL)
              )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};

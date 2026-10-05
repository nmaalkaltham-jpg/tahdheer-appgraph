<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تقييم المخاطر وإساءة الاستعمال — المرحلة ٠٥١ (§١٠٣ §١٠٥).
 *
 * §١٠٥ تضع القيد الحاكم لكلّ ما هنا: «إشارات الخطر لا تُثبت إساءةً
 * تلقائيًّا». ولذلك يُخزَّن **التقييم** لا الحكم: الإشارات كما رُصدت،
 * والقرار المتّخذ، وسببه. فحين يشتكي زبونٌ من منعٍ يمكن قراءةُ ما رآه
 * النظام لا تخمينُه.
 *
 * وأربعة قرارات لا اثنان (§١٠٣): بين «اسمح» و«امنع» يقع «تحقّق» و«راجعْ
 * يدويًّا» — وهما ما يمنع نظامًا يحمي الإيراد من أن يُتلفه. متجرٌ يمنع بناءً
 * على تغيّر عنوان IP يمنع المسافرين وأصحاب الهواتف قبل أن يمنع لصًّا واحدًا.
 *
 * والمنع الآليّ محجوزٌ لما لا لبس فيه: تجاوزُ حدٍّ مُعلَن اشتراه الزبون
 * ووافق عليه. وما عداه يُرفع لإنسان.
 */
return new class extends Migration
{
    /** §١٠٣ — قرارات المخاطر. */
    private const DECISIONS = ['ALLOW', 'CHALLENGE', 'MANUAL_REVIEW', 'DENY'];

    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();

            /* ما جرى تقييمه: تنزيلٌ أو طلبٌ أو استرداد. */
            $table->string('subject_type');   // download|order|refund|account
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entitlement_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('decision', self::DECISIONS);
            /* مجموع أوزان الإشارات — يُقرأ مع الإشارات لا وحده. */
            $table->unsignedSmallInteger('score')->default(0);

            /*
             * الإشارات كما رُصدت: رمزُ كلٍّ ووزنُه وما قيس فيها. حفظُ الدرجة
             * وحدها يجعل «لماذا مُنع هذا الزبون؟» سؤالًا بلا إجابة بعد شهر.
             */
            $table->json('signals');
            $table->string('reason', 500)->nullable();

            /*
             * لا عنوان IP خامًا (§١٠٦): يُخزَّن مُجزَّأً فيبقى مقارنةُ
             * «أهو العنوان نفسه؟» ممكنةً بلا الاحتفاظ بمعرّفٍ شخصيّ.
             */
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();

            /* مراجعة الإنسان — القرار النهائيّ حين يُرفع. */
            $table->enum('review_outcome', ['PENDING', 'CLEARED', 'CONFIRMED_ABUSE'])->nullable();
            $table->unsignedBigInteger('reviewed_by_admin_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['decision', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['review_outcome', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            /*
             * ما رُفع للمراجعة يحمل حالةَ مراجعة. بدونه تختفي الحالات
             * المرفوعة في الجدول ولا يعرف أحدٌ أنّ عليه النظر فيها.
             */
            DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_review_tracked CHECK (
                decision NOT IN ('MANUAL_REVIEW', 'DENY') OR review_outcome IS NOT NULL
            )");

            // ومنعٌ بلا سببٍ مكتوب لا يُشرح لزبونٍ ولا يُراجَع.
            DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_denial_has_reason CHECK (
                decision <> 'DENY' OR reason IS NOT NULL
            )");

            DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_review_closed_has_time CHECK (
                review_outcome IS NULL OR review_outcome = 'PENDING' OR reviewed_at IS NOT NULL
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ تدقيق خريطة الموقع — الحلقة التي كانت مفقودة بين أمرٍ يعمل وشاشةٍ مغلقة.
 *
 * `sitemap:audit` مجدولٌ كلّ ليلة في ٠٣:٤٠ ويقيس نقاء الخريطة وتغطيتها
 * وما طُرد منها ولماذا. ثمّ يطبع نتيجته على المخرَج القياسيّ — وفي حاوية،
 * المخرَج القياسيّ لا يقرؤه أحد. فالقياس يجري كلّ ليلة ويضيع كلّ ليلة.
 *
 * وبوّابة شاشة `/seo-health/sitemap` تسمّي الناقص حرفيًّا: «تخزين نتائج
 * `sitemap:audit` وعرضها». هذا هو التخزين.
 *
 * ─── ولماذا تاريخٌ لا صفٌّ واحد ───
 *
 * «هل تحسّنت التغطية بعد إصلاح الأمس؟» سؤالٌ لا يُجاب بآخر قياسٍ وحده.
 * وصفٌّ واحد يُدهَس كلّ ليلة يجعل كلّ ليلةٍ هي الليلة الأولى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sitemap_audits', function (Blueprint $table) {
            $table->id();
            /* بلا منطقة زمنيّة كبقيّة المستودع (قاعدة ٣٠). */
            $table->timestamp('ran_at')->index();
            $table->boolean('clean');
            $table->unsignedInteger('offered');
            $table->unsignedInteger('kept');
            $table->unsignedInteger('expelled');
            /*
             * `null` = «غير مقيس» لا صفر: خريطةٌ لم تُعرض فيها روابط نقاؤها
             * مجهول، وصفرٌ مكانه يُقرأ «صفر بالمئة» — أي عطلٌ تامّ.
             */
            $table->decimal('coverage_pct', 5, 2)->nullable();
            $table->unsignedInteger('missing_count');
            /* التقرير كاملًا: العيّنات وأسباب الطرد والمولّدات الخاملة. */
            $table->json('report');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sitemap_audits');
    }
};

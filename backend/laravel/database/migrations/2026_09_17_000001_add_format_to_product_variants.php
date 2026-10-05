<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صيغة الملفّ على الصيغة — العمود الوحيد الذي ينقص مُبدِّل الصيغ.
 *
 * §٤١ تعرّف `product_variants` بأنّها «وحدة المخزون والهويّة القابلة للبيع»،
 * وفي متجرٍ للملفّات التعليميّة تكون الصيغةُ القابلة للبيع هي **صيغة الملفّ**:
 * الدرس نفسه بـWord أو PowerPoint أو PDF، بسعرٍ لكلٍّ. وهذا بالضبط ما ترسمه
 * صفحة المنتج في التصميم المعتمد.
 *
 * والعمود ليس تكرارًا لـ`products.format`: ذاك يصف منتجًا قديمًا واحدًا بصيغةٍ
 * واحدة، وهذا يصف عضوًا في مجموعةٍ قد تحمل أربعةً منها. الردم أدناه ينسخ
 * القيمة مرّةً عبر جسر `legacy_product_id` ثمّ ينفصل المساران.
 *
 * فارغٌ يعني «غير معروفة» لا «بلا صيغة» (§١٥٨): منتجان في الكتالوج بلا صيغة
 * مسجَّلة، ولا تُلفَّق لهما واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_variants', 'format')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('format', 16)->nullable()->after('title');
        });

        /*
         * الردم عبر الجسر، وبتحديثٍ واحد لا حلقةِ صفوف: الجسر فريدٌ على
         * الطرفين فالربط واحدٌ لواحد بلا احتمال تضاعف.
         */
        DB::table('product_variants')
            ->whereNotNull('legacy_product_id')
            ->update([
                'format' => DB::raw(
                    '(select nullif(p.format, \'\') from products p where p.id = product_variants.legacy_product_id)'
                ),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_variants', 'format')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};

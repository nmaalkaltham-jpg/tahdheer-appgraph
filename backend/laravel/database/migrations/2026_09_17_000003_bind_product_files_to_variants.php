<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط ملفّ المنتج بصيغته — ما يجعل مُبدِّل الصيغ صادقًا لا زينة.
 *
 * بدون هذا العمود يختار المشتري «PowerPoint»، ويدفع سعر PowerPoint، ويُسلَّم
 * ملفّ Word — لأنّ `DownloadDelivery::assetFor` تختار بـ`product_id` وحده.
 * وهو عيبٌ لا يظهر في أيّ اختبارٍ قائم لأنّ كلّ منتجٍ اليوم بصيغةٍ واحدة.
 *
 * **فارغٌ يعني «لكلّ الصيغ»** لا «بلا صيغة»، وهو الافتراض الذي يُبقي كلّ
 * استحقاقٍ قائم يعمل بلا ردم: ستّةٌ وعشرون ملفًّا مرتبطةً بمنتجاتها تبقى
 * كما هي، ويعلوها لاحقًا ملفٌّ خاصٌّ بصيغةٍ حين يُؤلَّف.
 *
 * فلا ردم هنا عمدًا: ردمُ العمود بصيغة المنتج الوحيدة يبدو صحيحًا اليوم، ثمّ
 * يمنع غدًا ملفًّا مشتركًا بين الصيغ (دليل المعلّم مثلًا) من الوصول إلى غير
 * الصيغة التي وُلد تحتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_files', 'product_variant_id')) {
            return;
        }

        Schema::table('product_files', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();

            /* الاستعلام الحارّ: «ملفّات هذا المنتج لهذه الصيغة، بهذا الدور». */
            $table->index(['product_id', 'product_variant_id', 'role'], 'product_files_variant_role_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_files', 'product_variant_id')) {
            return;
        }

        Schema::table('product_files', function (Blueprint $table) {
            $table->dropIndex('product_files_variant_role_idx');
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};

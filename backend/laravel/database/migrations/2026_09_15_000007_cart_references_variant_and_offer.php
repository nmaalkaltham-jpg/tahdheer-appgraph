<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * السلّة تشير إلى الصيغة والعرض — المرحلتان ٠٤٩ و٠٥٠ (§٩٨ §١٠٠).
 *
 * `cart_items` اليوم: منتجٌ وكمّيّة، وقيدُ فرادةٍ على `(cart_id, product_id)`.
 * أي أنّ «مخطّط المعلّم A4» و«مخطّطه A5» لا يجتمعان في سلّةٍ واحدة — الثاني
 * يُبتلع صامتًا بوصفه تكرارًا للأوّل. ولا موضع لعرضٍ: شراءٌ لمرّة واشتراكٌ
 * شهريّ للمنتج نفسه لا يُفرَّق بينهما.
 *
 * فالنموذج الكونيّ الذي بُني في المراحل ٠٠٩–٠١٣ **لا يمكن بيعه** قبل هذه
 * الهجرة: تُنمذَج الصيغ والعروض ثمّ تُطوى كلّها عند أوّل «أضف إلى السلّة».
 *
 * إضافيّة: الأعمدة فارغةٌ مسموحة، فبنود السلال القائمة تبقى صالحة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_offer_id')->nullable()->after('product_variant_id')->constrained()->cascadeOnDelete();
            /* §٩٨ — الخيارات المختارة جزءٌ من هويّة البند لا زينة عرض. */
            $table->json('selected_options')->nullable()->after('qty');
        });

        /*
         * القيد القديم يسقط: كان يعني «منتجٌ واحد = بندٌ واحد»، وهو ما يمنع
         * صيغتين من المنتج نفسه. الجديد يضمّ الصيغة والعرض.
         *
         * و`COALESCE` ضرورةٌ لا تجميل: في Postgres لا يساوي NULL نفسَه، فقيد
         * فرادةٍ عاديّ على أعمدةٍ فارغة يسمح بتكرارٍ لا نهائيّ للبند القديم
         * الذي لا صيغة له — أي يُلغي القيد في الحالة الشائعة تحديدًا.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cart_items DROP CONSTRAINT IF EXISTS cart_items_cart_id_product_id_unique');
            DB::statement('CREATE UNIQUE INDEX cart_items_line_identity_unique ON cart_items (
                cart_id, product_id, COALESCE(product_variant_id, 0), COALESCE(product_offer_id, 0)
            )');
            // الكمّيّة موجبة: بندٌ بكمّيّة صفر بندٌ محذوف لم يُحذف.
            DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_qty_positive CHECK (qty > 0)');
            /*
             * الصيغة والعرض يتلازمان: عرضٌ بلا صيغة يتيمٌ لا يُسعَّر، وصيغةٌ
             * بلا عرضٍ لا ثمن لها. أحدهما وحده حالةٌ نصفُ مُهاجَرة تُكتشف
             * عند الدفع لا عند الإضافة.
             */
            DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_variant_offer_together CHECK (
                (product_variant_id IS NULL) = (product_offer_id IS NULL)
            )');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS cart_items_line_identity_unique');
            DB::statement('ALTER TABLE cart_items DROP CONSTRAINT IF EXISTS cart_items_qty_positive');
            DB::statement('ALTER TABLE cart_items DROP CONSTRAINT IF EXISTS cart_items_variant_offer_together');
        }

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropConstrainedForeignId('product_offer_id');
            $table->dropColumn('selected_options');
            $table->unique(['cart_id', 'product_id']);
        });
    }
};

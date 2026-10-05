<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس طبقة التجميع — الطرف المنتَج من الوصلات.
 *
 * ─── ما الذي كُشف ───
 *
 * `order_items` لم يكن له فهرسٌ واحد غير مفتاحه الأساسيّ. وثلاثة استعلاماتٍ
 * في النطاقات الجديدة تبحث فيه بـ`product_id`:
 *
 *   • «منتجات لم تبع قطّ» — نفيُ وجودٍ لكلّ منتجٍ في الكتالوج.
 *   • «الأعلى إيرادًا» — وصلةٌ من الأصناف إلى المنتجات.
 *   • «منتجات في طلباتٍ مستردّة» — وصلةٌ ثالثة على العمود نفسه.
 *
 * والمفتاح الخارجيّ لا يُنشئ فهرسًا في PostgreSQL. فكان نفيُ الوجود يمسح
 * جدول الأصناف مرّةً لكلّ منتج: مسحٌ تربيعيّ لا يُرى على ستّةٍ وعشرين منتجًا
 * ويُسقط اللوحة على كتالوجٍ حقيقيّ.
 *
 * و`product_prices` مثله: «منتجات بلا سعر» نفيُ وجودٍ على `product_id` بلا
 * فهرس.
 *
 * ─── وما لم يُضف ───
 *
 * `product_files` له `(product_id, asset_id, role)` وبادئتُه تخدم النفي.
 * و`cart_items` يُمسح كاملًا في استعلام السلال المهجورة لأنّ الوصلة تبدأ منه
 * لا تنتهي إليه، ففهرسٌ على `product_id` كلفةُ كتابةٍ بلا مكسب قراءة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index('product_id', 'order_items_product_id_index');
        });

        Schema::table('product_prices', function (Blueprint $table): void {
            $table->index('product_id', 'product_prices_product_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_product_id_index');
        });

        Schema::table('product_prices', function (Blueprint $table): void {
            $table->dropIndex('product_prices_product_id_index');
        });
    }
};

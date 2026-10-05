<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `products.version` — عنوانُ إصدار المحتوى («إصدار ١٤٤٧هـ» مثلًا).
 *
 * فارغٌ افتراضًا ويُعرض للقراءة فقط: لا يُخترع له قيمةٌ من تاريخٍ أو رقمٍ
 * تسلسليّ، فالمنتج الذي لم يُوسَم بإصدارٍ يُعلَن بلا إصدار.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('version', 64)->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};

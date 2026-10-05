<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * استراتيجيّة تسليمٍ واحدة تحت عرض. العرض قد يحمل أكثر من واحدة (§٨).
 *
 * «التسليم استراتيجيّة لا قيمة منطقيّة»: منتجٌ هجين يحمل تنزيلًا رقميًّا
 * وشحنةً ماديّة معًا، فينقسم الطلب إلى وحدتَي تنفيذٍ مستقلّتين (§١٠ §٣٤).
 */
class OfferFulfillment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    /*
     * ─── ثلاثة ثوابتٍ زالت من هنا ───
     *
     * كانت `SHIPPED` و`INSTANT` و`SCHEDULED` مكتوبةً في هذا الصفّ، وهي أسئلةُ
     * **قدرة** لا صفاتُ صفٍّ في جدول: «هل يوجب هذا التسليمُ عنوانًا؟» جوابُه
     * واحدٌ لكلّ النظام. ونسخةٌ هنا ونسخةٌ في `ProductCapabilities` تتّفقان
     * اليوم حرفًا بحرف — وأوّلُ استراتيجيّة شحنٍ جديدة تُضاف إلى إحداهما دون
     * الأخرى تجعل الدفعَ يكفّ عن طلب العنوان بينما `ShipmentExecutor` ما يزال
     * يوجبه: يُؤخذ المال وتقف الوحدة عند «لا عنوان شحن».
     *
     * السؤال يُطرح على `ProductCapabilities` وحده.
     */

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class, 'product_offer_id');
    }
}

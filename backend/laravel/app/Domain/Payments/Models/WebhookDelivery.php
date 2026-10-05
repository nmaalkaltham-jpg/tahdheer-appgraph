<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\WebhookEvent;
use App\Domain\Privacy\RedactedJson;
use Illuminate\Database\Eloquent\Model;

/**
 * تسليمٌ وارد من مزوّد دفع، محفوظٌ كما وصل.
 *
 * الحمولة **لا تُطبَّع**: هي ما يُعاد تشغيله لاحقًا، وتطبيعُها يعني أنّ
 * إعادة التشغيل تُعالج شيئًا آخر غير الذي وصل. والاستثناء الوحيد رمزُ الطلب
 * في `redirect.url` (RedactedJson): مفتاحُ طلب المشتري وتنزيلاته، ولا يقرؤه
 * شيءٌ في المعالجة ولا في إعادة التشغيل.
 */
class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => RedactedJson::class,
            'signature_verified' => 'boolean',
            'succeeded' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** يُعيد بناء الحدث كما وصل — لا كما نفهمه اليوم. */
    public function toEvent(): WebhookEvent
    {
        return new WebhookEvent(
            provider: (string) $this->provider,
            chargeId: (string) $this->charge_id,
            orderNumber: (string) $this->order_number,
            succeeded: (bool) $this->succeeded,
            payload: (array) $this->payload,
            amountMinor: $this->amount_minor === null ? null : (int) $this->amount_minor,
            currency: $this->currency,
            kind: (string) ($this->kind ?? WebhookEvent::CHARGE),
            refundId: $this->refund_id,
            amountProblem: $this->amount_problem,
            merchantId: isset($this->payload['merchant']['id']) ? (string) $this->payload['merchant']['id'] : null,
        );
    }

    /** هل حُسم أمرُه؟ المحسوم لا يُعاد تشغيله إلّا بقرارٍ صريح. */
    public function isSettled(): bool
    {
        return in_array($this->status, ['PROCESSED', 'IGNORED', 'MISMATCH'], true);
    }

    public function isReplayable(): bool
    {
        // الفاشل والذي لم يُطابق طلبًا هما ما يستحقّ إعادةً: الأوّل لعطلٍ
        // عندنا، والثاني لأنّ الطلب قد يكون وصل بعد الحدث.
        /*
         * و`MISMATCH`: تحصيلٌ رُفض تمويله يُعاد تقييمه بعد حلٍّ مشروع (أُعيد
         * فتح الطلب مثلًا). الإعادة تمرّ بالحارس نفسه — لا تتجاوزه.
         */
        return in_array($this->status, ['FAILED', 'UNMATCHED', 'PROCESSED', 'MISMATCH'], true);
    }
}

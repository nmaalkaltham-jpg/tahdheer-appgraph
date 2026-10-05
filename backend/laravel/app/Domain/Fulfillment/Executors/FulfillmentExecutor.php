<?php

namespace App\Domain\Fulfillment\Executors;

use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * منفّذ استراتيجيّة تسليمٍ واحدة أو أكثر (§٩).
 *
 * المنسّق كان يعرف كلّ استراتيجيّة بنفسه عبر `match`، فكان كلّ نظامٍ جديد
 * يوجب تعديله. صار يوزّع على منفّذين، فإضافةُ الحجز أو التزويد لاحقًا صنفٌ
 * جديد لا جراحةٌ في المنسّق.
 *
 * والعقد يُلزم بأمرٍ واحد: لا يُعيد المنفّذ `FULFILLED` إلّا وقد وقع شيءٌ
 * فعليّ — استحقاقٌ مُنح، أو مفتاحٌ أُسنِد، أو شحنةٌ أُنشئت. وإلّا فـ
 * `PENDING` بسببٍ مكتوب. حالةٌ نهائيّة كاذبة تُقفل الطلب ولا يصل الزبون شيء.
 */
interface FulfillmentExecutor
{
    /** @return list<string> الاستراتيجيّات التي يتولّاها هذا المنفّذ. */
    public function strategies(): array;

    public function execute(FulfillmentUnit $unit, Order $order, OrderItem $item): FulfillmentOutcome;

    /**
     * يسحب ما مُنح — عند الاسترداد أو إلغاء الطلب.
     *
     * ─── لماذا على العقد لا في خدمة الاسترداد ───
     *
     * كان السحب يعرف نوعًا واحدًا: `RefundService` يُلغي الاستحقاقات ولا
     * يمسّ شيئًا غيرها. فمفتاحُ الرخصة يبقى مُسنَدًا للزبون بعد ردّ ماله،
     * ووحدةُ التنفيذ تبقى `FULFILLED` فيُحسب الطلب مسلَّمًا. أي أنّ كلّ
     * مزوّدٍ جديد كان يجب أن يُذكر بالاسم في خدمة الاسترداد، وهو بعينه
     * التفرّع الذي أُزيل من التنفيذ ثمّ بقي في نقيضه.
     *
     * ─── والصدق مطلوبٌ هنا كما في التنفيذ ───
     *
     * ما لا يُسحب يُقال لا يُدَّعى: طردٌ سُلِّم لا «يُسحب» بأمرٍ في قاعدة
     * بيانات — يُرتجَع. فتُعاد رسالةٌ تقول ذلك للمشغّل، ولا تُعلَّم الوحدة
     * `REVOKED` كذبًا.
     *
     * @return string|null وصفُ ما سُحب للتدقيق، أو `null` حين لا شيء يُسحب.
     */
    public function revoke(FulfillmentUnit $unit, Order $order, OrderItem $item, string $reason): ?string;
}

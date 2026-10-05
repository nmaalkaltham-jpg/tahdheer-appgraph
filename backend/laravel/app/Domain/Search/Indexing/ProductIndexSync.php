<?php

declare(strict_types=1);

namespace App\Domain\Search\Indexing;

use App\Domain\Outbox\OutboxWriter;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * الطرف المنتِج لسلسلة فهرسة المنتجات — وكان مفقودًا كلّه.
 *
 * ─── العطب الذي وُجدت له ───
 *
 * `SearchOutboxSubscriber` يسجّل مستهلكًا لـ`product.published` و
 * `product.updated` يدفع `SyncProductJob`. و`IndexingTest` يثبت أنّه يعمل —
 * لأنّه يكتب صفّ الصندوق بيده. أمّا في التطبيق فلا سطر واحد يكتب هذين
 * النوعين: `grep -rhoE "eventType: '[a-z_.]+'"` يعيد اثني عشر نوعًا ليس
 * فيهما. أي أنّ المستهلك ينتظر حدثًا لا منتج له.
 *
 * والأثر مقيسٌ بالمعنى لا بالخطأ: المنتج يُنشر فلا يدخل الفهرس، ويُعدَّل
 * سعرُه فيبقى القديم معروضًا في البحث، و**يُسحَب فيبقى قابلًا للبحث
 * والشراء منه** — لأنّ `Indexer::syncProduct` هو وحده من يحذف الوثيقة، ولم
 * يكن أحد ينادِه. ولا خطأ يُرفع في أيّ من هذه: الفهرس يجيب بما فيه بثقة.
 *
 * ─── لماذا خدمةٌ لا سطرٌ في وحدة التحكّم ───
 *
 * مسارات كتابة المنتج أربعة اليوم وستزيد. واشتقاق المفتاح مكتوبًا في كلّ
 * مستدعٍ يعني أربع نسخٍ تتباعد، وأوّل نسخةٍ تُنسى هي منتجٌ لا يصل الفهرس.
 * وهي الحجّة نفسها التي أخرجت `ProductUrlSync` من وحدة التحكّم.
 *
 * ─── ولماذا مفتاح الخمول فريدٌ لكلّ نداء ───
 *
 * المفتاح هنا ليس بصمة حالةٍ بل معرّفًا فريدًا، والاختيار مقصودٌ ومقيس.
 *
 * جرّبتُ اشتقاقه من `updated_at` كما يشتقّه `NavigationPublishService` من
 * `version`. وأسقطه اختبارٌ كتبتُه للحالة: العمود `timestamp(0)`، و
 * `Model::setUpdatedAt` يمرّ القيمة بـ`fromDateTime` فتُقصّ إلى الثانية
 * **في الذاكرة أيضًا** لا في القاعدة وحدها. فتعديلان على المنتج نفسه في
 * الثانية الواحدة — تصحيحُ سعرٍ بعد سعر — يحملان المفتاح ذاته، فيُبتلَع
 * الثاني ويبقى السعر الأوّل في الفهرس بلا خطأ يُرفع. (مقيس: حدثٌ واحد
 * لتعديلين.) ولا عمود `version` في `products` يُشتقّ منه بديل.
 *
 * والمقايضة بعدها واضحة الاتّجاه: مفتاحٌ يبتلع يُنتج **بحثًا يكذب صامتًا**،
 * ومفتاحٌ فريد يُنتج في أسوأ حال **إعادة فهرسةٍ زائدة** — و`SyncProductJob`
 * خاملٌ بعقده: يقرأ المنتج من القاعدة وقت التنفيذ لا من حمولة الحدث، فمزامنة
 * مكرّرة تكتب الوثيقة نفسها. فالفرادة هنا اختيارُ الجهة التي يُخطئ نحوها.
 */
final class ProductIndexSync
{
    public const PUBLISHED = 'product.published';

    public const UPDATED = 'product.updated';

    public const WITHDRAWN = 'product.withdrawn';

    public function __construct(private readonly OutboxWriter $outbox) {}

    /** نُشر — تدخل وثيقته الفهرس. */
    public function published(Product $product): void
    {
        $this->announce($product, self::PUBLISHED);
    }

    /** تغيّر وهو منشور — تُحدَّث وثيقته. وغير المنشور لا وثيقة له فالمزامنة حذفٌ خامل. */
    public function updated(Product $product): void
    {
        $this->announce($product, self::UPDATED);
    }

    /** سُحب إلى مسودّة أو تقاعد — تُحذف وثيقته، وهذا هو الطرف الذي كان غيابه يُبقيه معروضًا. */
    public function withdrawn(Product $product): void
    {
        $this->announce($product, self::WITHDRAWN);
    }

    /**
     * يُنادى **داخل معاملة المستدعي** دائمًا (قاعدة `OutboxWriter`): الحالة
     * والحدث يلتزمان معًا أو يسقطان معًا. ولا حمولة زائدة عمّا يحتاجه
     * المستهلك: المعرّف وحده، والباقي يُقرأ من القاعدة وقت التنفيذ.
     */
    private function announce(Product $product, string $eventType): void
    {
        $this->outbox->write(
            aggregateType: 'product',
            aggregateId: (string) $product->id,
            eventType: $eventType,
            payload: ['productId' => (int) $product->id, 'status' => (string) $product->status],
            idempotencyKey: "product:{$product->id}:{$eventType}:".Str::uuid()->toString(),
        );
    }
}

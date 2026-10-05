<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Outbox\OutboxWriter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * الجهة **الوحيدة** التي تلمس المخزون (§٢٩).
 *
 * كلّ تغييرٍ في الرصيد يمرّ من هنا، ويكتب سطر دفترٍ في المعاملة نفسها.
 * كتابةُ `stock_levels` مباشرةً تكسر المراجعة: يختلف الجرد عن النظام ولا
 * يبقى ما يُقرأ لمعرفة السبب.
 *
 * والقفل متشائم (`lockForUpdate`) لا متفائل: بيعُ آخر قطعةٍ لزبونين معًا
 * سباقٌ حقيقيّ يقع تحت الحمل، ونتيجتُه شحنةٌ لا وجود لها.
 */
class InventoryService
{
    /** أجل الحجز الافتراضيّ — يكفي لإتمام دفعٍ ولا يجمّد مخزونًا طويلًا. */
    public const HOLD_MINUTES = 30;

    public function __construct(private readonly OutboxWriter $outbox) {}

    /**
     * حدثُ تغيّر التوافر (§١١٣ §١١٤).
     *
     * يُصدَر من هنا لا من مستدعٍ يتذكّر نداءه: واجهة المتجر وفهرس البحث
     * ومشروع التوافر تشترك فيه، وربطُها بذاكرة وحدةِ تحكّمٍ يعني أنّ مسارًا
     * واحدًا يُنسى فيبقى منتجٌ معروضًا وقد نفد.
     *
     * يُنادى داخل معاملة المستدعي دائمًا، فيلتزم الحدث مع الحركة أو يسقطان.
     */
    private function announce(StockLevel $level, string $movement, string $key): void
    {
        $this->outbox->write(
            aggregateType: 'product_variant',
            aggregateId: (string) $level->product_variant_id,
            eventType: 'inventory.availability.changed',
            payload: [
                'variantId' => (int) $level->product_variant_id,
                'locationId' => (int) $level->inventory_location_id,
                'movement' => $movement,
                'onHand' => (int) $level->on_hand,
                'reserved' => (int) $level->reserved,
                'available' => $level->available(),
            ],
            idempotencyKey: $key,
        );
    }

    /** إدخال مخزون. */
    public function receive(int $variantId, int $locationId, int $quantity, string $reason, ?string $key = null): StockLevel
    {
        if ($quantity <= 0) {
            throw new RuntimeException('الإدخال يكون بمقدارٍ موجب.');
        }

        return $this->move($variantId, $locationId, 'RECEIPT', $quantity, $reason, null, null, $key);
    }

    /** تصحيحٌ بسببٍ مكتوب — موجبًا أو سالبًا. */
    public function adjust(int $variantId, int $locationId, int $delta, string $reason, ?int $actorId = null, ?string $key = null): StockLevel
    {
        if ($delta === 0) {
            throw new RuntimeException('تصحيحٌ بمقدار صفر ليس حركة.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('كلّ تصحيحٍ يحتاج سببًا مكتوبًا — الدفتر يُقرأ لاحقًا.');
        }

        return $this->move($variantId, $locationId, 'ADJUSTMENT', $delta, $reason, null, null, $key, $actorId);
    }

    /**
     * حجزٌ بأجل. يفشل صراحةً حين لا يكفي المتاح — ولا يحجز جزئيًّا بصمت.
     */
    public function reserve(
        int $variantId,
        int $locationId,
        int $quantity,
        string $holderType,
        int $holderId,
        ?int $minutes = null,
        ?int $lineId = null,
    ): InventoryReservation {
        if ($quantity <= 0) {
            throw new RuntimeException('الحجز يكون بمقدارٍ موجب.');
        }

        /*
         * المفتاح بالبند لا بالحامل وحده: بندان في طلبٍ واحد على الصيغة
         * نفسها (حزمةٌ ومكوّنُها مثلًا) كانا يتقاسمان مفتاحًا، فيعود الثاني
         * بحجز الأوّل ويُباع اثنان وقد حُجز واحد.
         */
        $line = $lineId === null ? '' : "line{$lineId}:";
        $key = "reserve:{$holderType}:{$holderId}:{$line}{$variantId}:{$locationId}";

        return DB::transaction(function () use ($variantId, $locationId, $quantity, $holderType, $holderId, $minutes, $key) {
            $existing = InventoryReservation::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing; // §٢١ — إعادة النداء لا تحجز مرّتين
            }

            $level = $this->lockLevel($variantId, $locationId);

            if ($level->available() < $quantity) {
                throw new RuntimeException(
                    "لا يكفي المخزون: المتاح {$level->available()} والمطلوب {$quantity}."
                );
            }

            $level->reserved += $quantity;
            $level->save();

            $this->writeMovement($variantId, $locationId, 'RESERVATION', -$quantity, $level->on_hand - $level->reserved,
                'حجزٌ مؤقّت', $holderType, $holderId, $key.':ledger');

            $this->announce($level, 'RESERVATION', $key.':event');

            return InventoryReservation::create([
                'product_variant_id' => $variantId,
                'inventory_location_id' => $locationId,
                'quantity' => $quantity,
                'status' => 'HELD',
                'holder_type' => $holderType,
                'holder_id' => $holderId,
                'expires_at' => now()->addMinutes($minutes ?? self::HOLD_MINUTES),
                'idempotency_key' => $key,
            ]);
        });
    }

    /**
     * يُسوّي حجوزات حاملٍ بعينه — بيعًا أو إفراجًا.
     *
     * ─── الحلقة التي كانت مفتوحة ───
     *
     * `consume()` و`release()` مكتوبتان منذ بناء المخزون **ولا يستدعيهما شيء
     * في الإنتاج**: الحجز يقع عند الدفع ثمّ لا يُسوّى أبدًا. فيبقى معلّقًا
     * حتّى تنتهي مهلتُه فيُفرَج عنه — أي أنّ **بيعًا تامًّا يُعيد البضاعة إلى
     * المتاح**، و`on_hand` لا ينقص أبدًا مهما بِيع. والعدّ في اللوحة يقول
     * إنّ في المستودع ما ليس فيه.
     *
     * ولم يظهر ذلك قبل اليوم لأنّ لا منتجٍ متعقَّب كان يُباع أصلًا: بوّابة
     * السلّة ترفض غير الرقميّ، والتأليف يكتب `UNLIMITED` لكلّ صيغة.
     *
     * @param  string  $status  `CONSUMED` عند البيع، أو حالةُ إفراج.
     * @return int عدد الحجوزات التي سُوّيت.
     */
    public function settleFor(string $holderType, int $holderId, string $status): int
    {
        $held = InventoryReservation::query()
            ->where('holder_type', $holderType)
            ->where('holder_id', $holderId)
            ->where('status', 'HELD')
            ->get();

        foreach ($held as $reservation) {
            $status === 'CONSUMED'
                ? $this->consume($reservation)
                : $this->release($reservation, $status);
        }

        return $held->count();
    }

    /** إفراجٌ عن حجزٍ لم يُدفع — المخزون يعود متاحًا. */
    public function release(InventoryReservation $reservation, string $status = 'RELEASED'): InventoryReservation
    {
        return DB::transaction(function () use ($reservation, $status) {
            $reservation = $this->lockReservation($reservation);

            if (! $reservation->isOpen()) {
                return $reservation; // خامل التكرار
            }

            $level = $this->lockLevel($reservation->product_variant_id, $reservation->inventory_location_id);
            $level->reserved -= $reservation->quantity;
            $level->save();

            $this->writeMovement(
                $reservation->product_variant_id, $reservation->inventory_location_id, 'RELEASE',
                $reservation->quantity, $level->on_hand - $level->reserved,
                $status === 'EXPIRED' ? 'انتهى أجل الحجز' : 'إفراجٌ عن حجز',
                $reservation->holder_type, $reservation->holder_id,
                "release:{$reservation->id}",
            );

            $this->announce($level, 'RELEASE', "release:{$reservation->id}:event");

            $reservation->update(['status' => $status, 'settled_at' => now()]);

            return $reservation;
        });
    }

    /**
     * استهلاك الحجز عند البيع: المحجوز ينزل والموجود ينزل معه.
     *
     * الخطأ الذي يمنعه هذا: إنقاصُ `on_hand` وحده يترك `reserved` مرفوعًا
     * إلى الأبد، فيختفي مخزونٌ موجودٌ من المتاح بلا أثر.
     */
    public function consume(InventoryReservation $reservation): InventoryReservation
    {
        return DB::transaction(function () use ($reservation) {
            $reservation = $this->lockReservation($reservation);

            if (! $reservation->isOpen()) {
                return $reservation;
            }

            $level = $this->lockLevel($reservation->product_variant_id, $reservation->inventory_location_id);
            $level->reserved -= $reservation->quantity;
            $level->on_hand -= $reservation->quantity;
            $level->save();

            $this->writeMovement(
                $reservation->product_variant_id, $reservation->inventory_location_id, 'SALE',
                -$reservation->quantity, $level->on_hand - $level->reserved, 'بيع',
                $reservation->holder_type, $reservation->holder_id,
                "consume:{$reservation->id}",
            );

            $this->announce($level, 'SALE', "consume:{$reservation->id}:event");

            $reservation->update(['status' => 'CONSUMED', 'settled_at' => now()]);

            return $reservation;
        });
    }

    /**
     * الحجز يُقرأ تحت قفل صفّه قبل أن يُقرَّر مصيره.
     *
     * كانت الحالة تُقرأ بلا قفل ثمّ يُقفل الرصيد: إفراجُ المجدول واستهلاكُ
     * التثبيت يقرآن «محجوز» معًا فينقص المحجوز مرّتين والموجود مرّة —
     * ويصير «المتاح» أكبر من الحقيقة.
     */
    private function lockReservation(InventoryReservation $reservation): InventoryReservation
    {
        return InventoryReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
    }

    /** يُفرج عن كلّ حجزٍ انتهى أجله. يُنادى من المجدول. */
    public function releaseExpired(): int
    {
        $count = 0;

        foreach (InventoryReservation::query()->expired()->get() as $reservation) {
            $this->release($reservation, 'EXPIRED');
            $count++;
        }

        return $count;
    }

    /** المتاح للبيع عبر المواقع المعروضة. */
    public function availableFor(int $variantId): int
    {
        return (int) StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->whereIn('inventory_location_id', InventoryLocation::query()->sellable()->pluck('id'))
            ->get()
            ->sum(fn (StockLevel $l) => $l->available());
    }

    /**
     * يعيد بناء الرصيد من الدفتر.
     *
     * هذا ما يجعل الدفتر مصدر الحقيقة فعلًا لا شعارًا: إن اختلف المُشتقّ
     * عن مجموع الحركات فالمُشتقّ هو الخاطئ، ويُصحَّح منه.
     */
    public function rebuildLevel(int $variantId, int $locationId): StockLevel
    {
        return DB::transaction(function () use ($variantId, $locationId) {
            $level = $this->lockLevel($variantId, $locationId);

            $onHand = (int) StockMovement::query()
                ->where('product_variant_id', $variantId)
                ->where('inventory_location_id', $locationId)
                ->whereIn('movement', ['RECEIPT', 'SALE', 'RETURN', 'ADJUSTMENT', 'TRANSFER', 'DAMAGE'])
                ->sum('quantity_delta');

            $held = (int) InventoryReservation::query()
                ->where('product_variant_id', $variantId)
                ->where('inventory_location_id', $locationId)
                ->where('status', 'HELD')
                ->sum('quantity');

            $level->update(['on_hand' => $onHand, 'reserved' => $held]);

            return $level;
        });
    }

    private function move(
        int $variantId, int $locationId, string $movement, int $delta,
        string $reason, ?string $refType, ?int $refId, ?string $key, ?int $actorId = null,
    ): StockLevel {
        return DB::transaction(function () use ($variantId, $locationId, $movement, $delta, $reason, $refType, $refId, $key, $actorId) {
            if ($key !== null && StockMovement::query()->where('idempotency_key', $key)->exists()) {
                return $this->lockLevel($variantId, $locationId);
            }

            $level = $this->lockLevel($variantId, $locationId);
            $level->on_hand += $delta;
            $level->save();

            $written = $this->writeMovement($variantId, $locationId, $movement, $delta,
                $level->on_hand - $level->reserved, $reason, $refType, $refId, $key, $actorId);

            /*
             * مفتاح الحدث مشتقٌّ من مفتاح الحركة حين وُجد: حركةٌ خاملة
             * التكرار يجب ألّا تُنتج حدثين. وحين لا مفتاح — نداءٌ داخليّ بلا
             * خمول — يُشتقّ من معرّف الحركة الذي كُتب للتوّ **هي** لا من
             * `max(id)`: معاملتان متزامنتان تقرآن الأكبر نفسه فيتصادم المفتاح
             * ويُبتلع حدثُ الثانية بصمت.
             */
            $this->announce(
                $level,
                $movement,
                $key !== null ? $key.':event' : "stock:{$variantId}:{$locationId}:{$written->id}",
            );

            return $level;
        });
    }

    private function writeMovement(
        int $variantId, int $locationId, string $movement, int $delta, int $balanceAfter,
        string $reason, ?string $refType, ?int $refId, ?string $key, ?int $actorId = null,
    ): StockMovement {
        return StockMovement::create([
            'product_variant_id' => $variantId,
            'inventory_location_id' => $locationId,
            'movement' => $movement,
            'quantity_delta' => $delta,
            'balance_after' => $balanceAfter,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'reason' => $reason,
            'actor_admin_user_id' => $actorId,
            'idempotency_key' => $key,
        ]);
    }

    private function lockLevel(int $variantId, int $locationId): StockLevel
    {
        StockLevel::query()->firstOrCreate(
            ['product_variant_id' => $variantId, 'inventory_location_id' => $locationId],
            ['on_hand' => 0, 'reserved' => 0],
        );

        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('inventory_location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}

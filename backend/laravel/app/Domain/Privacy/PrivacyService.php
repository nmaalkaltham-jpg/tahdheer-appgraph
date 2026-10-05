<?php

namespace App\Domain\Privacy;

use App\Domain\Privacy\Models\AnonymizationRecord;
use App\Domain\Privacy\Models\ConsentRecord;
use App\Domain\Privacy\Models\DataSubjectRequest;
use App\Domain\Privacy\Models\LegalHold;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * حقوق صاحب البيانات (§١٠٦).
 *
 * ─── التوتّر الذي يحلّه هذا الصنف ───
 *
 * «احذف بياناتي» و«احتفظ بالفواتير» أمران قانونيّان متعارضان. الحذف الكامل
 * يخالف نظام الفوترة، ورفضُ الحذف يخالف حقّ المحو.
 *
 * والحلّ **إخفاء الهويّة لا الحذف**: يُمحى الشخص ويبقى الأثر الماليّ. الطلب
 * يبقى بمبلغه وضريبته وتاريخه ولا يبقى فيه اسمٌ ولا بريدٌ ولا هاتف. فيُرضى
 * الحقّان معًا بلا أن يكذب أحدهما على الآخر — وهذا ما يجعل التنفيذ ممكنًا
 * أصلًا بدل أن يبقى بندًا في سياسةٍ لا يُنفَّذ.
 *
 * ─── لماذا يُسجَّل المحو ───
 *
 * يبقى **أنّ المحو وقع** حتّى بعد محو الشخص: بغير ذلك يستحيل إثبات
 * الامتثال، ويبدو الطلبُ المجهولُ الاسم عطلًا في البيانات لا قرارًا نُفِّذ.
 */
class PrivacyService
{
    /** المهلة النظاميّة للردّ على طلب صاحب البيانات. */
    public const RESPONSE_DAYS = 30;

    /** القيمة التي تحلّ محلّ ما مُحي — صريحةٌ لا فارغة. */
    public const REDACTED = '[محذوف بطلب صاحب البيانات]';

    /**
     * كلّ ما نحتفظ به عن هذا الزبون، بالشكل الذي يُسلَّم له.
     *
     * تُجمع من الجداول مباشرةً لا من نماذج العرض: ما يُسلَّم هو ما هو
     * مخزَّنٌ فعلًا، لا ما تعرضه الواجهة.
     *
     * @return array<string, mixed>
     */
    public function export(Customer $customer): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'subject' => [
                'id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'locale' => $customer->locale,
                'created_at' => $customer->created_at?->toIso8601String(),
            ],
            'orders' => DB::table('orders')->where('customer_id', $customer->id)
                ->get(['number', 'status', 'total_minor', 'vat_minor', 'created_at'])->all(),
            'order_items' => DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.customer_id', $customer->id)
                ->get(['orders.number', 'order_items.title_snapshot', 'order_items.price_snapshot_minor'])->all(),
            'entitlements' => DB::table('entitlements')->where('customer_id', $customer->id)
                ->get(['product_id', 'status', 'granted_at', 'download_count'])->all(),
            'addresses' => DB::table('addresses')->where('customer_id', $customer->id)
                ->get(['label', 'recipient_name', 'phone', 'city', 'district', 'street'])->all(),
            'reviews' => DB::table('reviews')->where('customer_id', $customer->id)
                ->get(['product_id', 'rating', 'body', 'author_role', 'author_city', 'created_at'])->all(),
            'consents' => ConsentRecord::query()->where('customer_id', $customer->id)
                ->orderBy('recorded_at')
                ->get(['purpose', 'granted', 'policy_version', 'source', 'recorded_at'])->all(),
            /*
             * تقييمات المخاطر تُصدَّر بقرارها وإشاراتها: صاحب البيانات يحقّ
             * له معرفة ما قرّره النظام بشأنه. ولا تُصدَّر التجزئات — فهي
             * بياناتٌ عنه لا بياناتٌ له.
             */
            'risk_decisions' => DB::table('risk_assessments')->where('customer_id', $customer->id)
                ->get(['decision', 'reason', 'created_at'])->all(),
        ];
    }

    /** أيّ حجزٍ قانونيّ قائم يمنع المحو؟ */
    public function activeHold(Customer $customer): ?LegalHold
    {
        return LegalHold::query()
            ->where('subject_type', 'customer')
            ->where('subject_id', $customer->id)
            ->whereNull('released_at')
            ->first();
    }

    /**
     * محو الشخص مع إبقاء الأثر الماليّ.
     *
     * @return array{anonymized: bool, reason: string|null, recordsAffected: int}
     */
    public function erase(Customer $customer, ?DataSubjectRequest $request = null): array
    {
        $hold = $this->activeHold($customer);

        if ($hold !== null) {
            /*
             * الحجز القانونيّ يغلب حقّ المحو: الامتثال أثناء نزاعٍ جارٍ
             * إتلافُ دليل. والرفض يُعلَّل لصاحب البيانات ولا يُصمَت عنه.
             */
            return [
                'anonymized' => false,
                'reason' => "حجزٌ قانونيّ قائم يمنع المحو: {$hold->reason}",
                'recordsAffected' => 0,
            ];
        }

        return DB::transaction(function () use ($customer, $request) {
            $affected = 0;

            /*
             * الطلبات: البريد والهاتف يُمحيان، والمبلغ والضريبة والتاريخ
             * تبقى. هذا هو بيت القصيد — سجلٌّ ماليٌّ كاملٌ بلا شخص.
             */
            $affected += DB::table('orders')->where('customer_id', $customer->id)->update([
                'email' => self::REDACTED,
                'phone' => null,
                'tax_org_name' => null,
                'tax_vat_number' => null,
            ]);

            $affected += DB::table('addresses')->where('customer_id', $customer->id)->delete();

            /*
             * لقطات العناوين على الطلبات تُطمس ولا تُحذف: الشحنة وقعت إلى
             * مدينةٍ ما، وحذفُ الصفّ يُفقد سجلّ التسليم نفسه.
             */
            $affected += DB::table('order_addresses')
                ->whereIn('order_id', DB::table('orders')->where('customer_id', $customer->id)->select('id'))
                ->update([
                    'recipient_name' => self::REDACTED,
                    'phone' => self::REDACTED,
                    'street' => null,
                    'building_number' => null,
                    'secondary_number' => null,
                    'postal_code' => null,
                    'notes' => null,
                    'latitude' => null,
                    'longitude' => null,
                ]);

            /*
             * المراجعات: النصّ يبقى منشورًا — رأيٌ عن منتجٍ لا بياناتُ شخص —
             * والنسبة تسقط تلقائيًّا بطمس صفّ الزبون.
             *
             * أمّا الصفة والمدينة فتُمحيان صراحةً. أُضيفتا في ٢٠٢٦-٠٩-١٧ لسطر
             * «معلّمة علوم، الرياض» في صفحة المنتج، وهما بالتركيب وصفٌ يدلّ
             * على شخصٍ بعينه: مهنةٌ ومدينةٌ منشورتان تحت تقييمٍ مؤرَّخ لمنتجٍ
             * محدّد. فبقاؤهما بعد المحو يُبقي الزبون معرَّفًا عمليًّا مهما
             * طُمس اسمه — وهو ما يُبطل المحو لا ينقصه.
             */
            $affected += DB::table('reviews')->where('customer_id', $customer->id)
                ->where(fn ($q) => $q->whereNotNull('author_role')->orWhereNotNull('author_city'))
                ->update(['author_role' => null, 'author_city' => null]);

            $affected += DB::table('customers')->where('id', $customer->id)->update([
                'email' => 'redacted+'.$customer->id.'@invalid.local',
                'name' => self::REDACTED,
                'phone' => null,
                'marketing_consent' => false,
            ]);

            AnonymizationRecord::create([
                'data_subject_request_id' => $request?->id,
                'subject_type' => 'customer',
                'subject_id' => $customer->id,
                'fields_cleared' => ['email', 'name', 'phone', 'addresses', 'order_contact', 'review_author_role', 'review_author_city'],
                'records_affected' => $affected,
                'retained_for' => 'السجلّ الماليّ (الطلبات والفواتير والمدفوعات) يبقى بحكم نظام الفوترة، بلا بيانات شخصيّة.',
            ]);

            return ['anonymized' => true, 'reason' => null, 'recordsAffected' => $affected];
        });
    }

    /**
     * تسجيل موافقة أو سحبها.
     *
     * السحب حدثٌ جديد لا حذفٌ للقديم: سجلّ الموافقة دليلُ امتثال، ومحوُه
     * يُفقد المتجر إثبات أنّه كان مأذونًا له يوم أرسل.
     */
    public function recordConsent(
        ?Customer $customer,
        string $purpose,
        bool $granted,
        string $policyVersion,
        string $source,
        ?string $wording = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $subjectRef = null,
    ): ConsentRecord {
        return ConsentRecord::create([
            'customer_id' => $customer?->id,
            'subject_ref' => $subjectRef,
            'purpose' => $purpose,
            'granted' => $granted,
            'policy_version' => $policyVersion,
            'wording_shown' => $wording,
            'source' => $source,
            'ip_hash' => $ip === null ? null : hash('sha256', $ip),
            'user_agent_hash' => $userAgent === null ? null : hash('sha256', $userAgent),
        ]);
    }

    /**
     * هل يملك هذا الزبون موافقةً سارية لهذا الغرض؟
     *
     * الأحدث يفوز: السجلّ تاريخٌ لا حالة، والموافقة الحاليّة هي آخر ما قاله
     * صاحب البيانات لا أوّل ما قاله.
     */
    public function hasConsent(Customer $customer, string $purpose): bool
    {
        $latest = ConsentRecord::query()
            ->where('customer_id', $customer->id)
            ->where('purpose', $purpose)
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->first();

        // الغياب ليس موافقة: ما لم يُؤذَن به صراحةً ممنوع.
        return $latest !== null && (bool) $latest->granted;
    }

    /** فتح طلبٍ بمهلته المحسوبة عند الاستلام. */
    public function openRequest(string $email, string $type, ?Customer $customer = null): DataSubjectRequest
    {
        return DataSubjectRequest::create([
            'number' => 'DSAR-'.now()->format('Ymd').'-'.mb_substr((string) hrtime(true), -6),
            'customer_id' => $customer?->id,
            'contact_email' => mb_strtolower(trim($email)),
            'type' => $type,
            'status' => 'IDENTITY_PENDING',
            'received_at' => now(),
            'due_at' => now()->addDays(self::RESPONSE_DAYS),
        ]);
    }
}

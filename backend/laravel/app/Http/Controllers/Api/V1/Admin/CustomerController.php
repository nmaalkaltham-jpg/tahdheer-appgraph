<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminValidation;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer accounts with their entitlement ledger (MIP §8.1). Counts come
 * from correlated subqueries — one statement per page, never N+1.
 */
class CustomerController extends Controller
{
    /** أعمدة الفرز المسموحة — قائمةٌ بيضاء لا اسمٌ يأتي من العميل. */
    private const SORTABLE = [
        'name' => 'customers.name',
        'email' => 'customers.email',
        'orders' => 'orders_count',
        'entitlements' => 'entitlements_count',
        'activeEntitlements' => 'active_entitlements_count',
        'createdAt' => 'customers.created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'sometimes|string|max:200',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
            'sort' => ['sometimes', Rule::in(array_keys(self::SORTABLE))],
            'dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ], AdminValidation::MESSAGES);

        $query = Customer::query()
            ->select('customers.*')
            ->selectSub(
                Order::query()->selectRaw('count(*)')->whereColumn('orders.customer_id', 'customers.id'),
                'orders_count',
            )
            ->selectSub(
                Entitlement::query()->selectRaw('count(*)')->whereColumn('entitlements.customer_id', 'customers.id'),
                'entitlements_count',
            )
            ->selectSub(
                Entitlement::query()->selectRaw('count(*)')
                    ->whereColumn('entitlements.customer_id', 'customers.id')
                    ->where('entitlements.status', 'ACTIVE'),
                'active_entitlements_count',
            );

        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $needle = '%'.trim($filters['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('email', 'like', $needle));
        }

        /*
         * الأعداد تُفرَز بأسماء الاستعلامات الفرعيّة أعلاه لا بعمودٍ حقيقيّ:
         * PostgreSQL يقبل الاسم المستعار في `ORDER BY`، وهو ما يجعل الفرز
         * بعدد الطلبات ممكنًا بلا وصلةٍ تُضاعف الصفوف.
         *
         * و`customers.id` قاطعٌ ثانويّ دائمًا: الفرز بعددٍ متكرّر بلا قاطع
         * ترتيبٌ غير محدَّد، فيظهر العميل نفسه في صفحتين ويختفي غيره.
         */
        $column = self::SORTABLE[$filters['sort'] ?? ''] ?? null;
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($column !== null) {
            $query->orderByRaw("{$column} {$direction}");
        }

        $paginator = $query->orderByDesc('customers.id')->paginate(
            perPage: (int) ($filters['perPage'] ?? 20),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Customer $c) => $this->row($c))->values(),
            'meta' => AdminValidation::meta($paginator),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $customer = Customer::query()->findOrFail($id);

        $orders = Order::query()->where('customer_id', $customer->id)->orderByDesc('id')->get();
        $entitlements = Entitlement::query()->where('customer_id', $customer->id)->orderByDesc('id')->get();
        $titles = DB::table('products')
            ->whereIn('id', $entitlements->pluck('product_id')->unique()->all())
            ->pluck('title', 'id');

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'locale' => $customer->locale,
                'marketingConsent' => (bool) $customer->marketing_consent,
                'createdAt' => $customer->created_at?->toIso8601String(),
                'ordersCount' => $orders->count(),
                'entitlementsCount' => $entitlements->count(),
                'activeEntitlementsCount' => $entitlements->where('status', 'ACTIVE')->count(),
                'orders' => $orders->map(fn (Order $o) => [
                    'id' => $o->id,
                    'number' => $o->number,
                    'status' => $o->status,
                    'totalMinor' => (int) $o->total_minor,
                    'createdAt' => $o->created_at?->toIso8601String(),
                ])->values(),
                'entitlements' => $entitlements->map(fn (Entitlement $e) => [
                    'id' => $e->id,
                    'productId' => $e->product_id,
                    'productTitle' => $titles[$e->product_id] ?? null,
                    'orderId' => $e->order_id,
                    'status' => $e->status,
                    'downloadCount' => (int) $e->download_count,
                    'downloadLimit' => $e->download_limit === null ? null : (int) $e->download_limit,
                    'grantedAt' => $e->granted_at?->toIso8601String(),
                    'expiresAt' => $e->expires_at?->toIso8601String(),
                    'revokedAt' => $e->revoked_at?->toIso8601String(),
                    'revocationReason' => $e->revocation_reason,
                ])->values(),
            ],
        ]);
    }

    private function row(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'marketingConsent' => (bool) $customer->marketing_consent,
            'ordersCount' => (int) $customer->orders_count,
            'entitlementsCount' => (int) $customer->entitlements_count,
            'activeEntitlementsCount' => (int) $customer->active_entitlements_count,
            'createdAt' => $customer->created_at?->toIso8601String(),
        ];
    }
}

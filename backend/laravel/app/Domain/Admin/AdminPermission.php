<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Http\Middleware\AdminAuthorize;

/**
 * قاموس صلاحيات لوحة الإدارة.
 *
 * هذا هو المصدر البرمجي المركزي لأسماء الصلاحيات المستخدمة
 * في مسارات Admin، بدل توزيع magic strings على ملف المسارات.
 *
 * لا تمثل هذه الطبقة Review أو Approval.
 * امتلاك الصلاحية يعني المرور مباشرة إلى Controller بعد
 * المصادقة والـ rate limiting.
 */
enum AdminPermission: string
{
    case AnalyticsRead = 'analytics.read';

    case AppearanceManage = 'appearance.manage';

    case AuditRead = 'audit.read';

    case ContentManage = 'content.manage';

    case CustomersRead = 'customers.read';

    case EntitlementsRevoke = 'entitlements.revoke';

    case IntegrationsManage = 'integrations.manage';

    case InventoryManage = 'inventory.manage';

    case NotesWrite = 'notes.write';

    case OperationsManage = 'operations.manage';

    case OrdersRead = 'orders.read';

    case OrdersRefund = 'orders.refund';

    case PaymentsManage = 'payments.manage';

    case ProductsCreate = 'products.create';

    case ProductsPublish = 'products.publish';

    case ProductsRead = 'products.read';

    case ProductsUpdate = 'products.update';

    case PromotionsManage = 'promotions.manage';

    case RedirectsManage = 'redirects.manage';

    case ReviewsModerate = 'reviews.moderate';

    case SearchIndexManage = 'search.index.manage';

    case SearchSynonymsManage = 'search.synonyms.manage';

    case ShippingManage = 'shipping.manage';

    case SystemManage = 'system.manage';

    case UrlEdit = 'url.edit';

    case UrlRead = 'url.read';

    case UsersManage = 'users.manage';

    /**
     * Middleware الذي يفهمه Laravel.
     */
    public function middleware(): string
    {
        return AdminAuthorize::class.':'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}

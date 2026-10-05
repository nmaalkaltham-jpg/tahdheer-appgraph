<?php

namespace App\Domain\Admin;

/**
 * RBAC role presets (MIP §8.4), backend-enforced on every admin route.
 *
 * HONESTY: the catalogue below lists ONLY permissions that an existing
 * admin endpoint actually checks. MIP §8.4 names a wider permission set
 * (files.*, seo.*, intl.manage, media.manage, navigation.publish,
 * settings.manage …); those are deliberately absent until the endpoints that
 * enforce them exist, so `/admin/me` never advertises a capability the
 * server cannot actually grant or refuse.
 *
 * `users.manage` graduated out of that waiting list: AdminUserController now
 * enforces it on every route under /admin/users, so it is real.
 */
enum AdminRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case ProductManager = 'product_manager';
    case OrderManager = 'order_manager';
    case SeoManager = 'seo_manager';
    case SearchManager = 'search_manager';
    case Analytics = 'analytics';
    case ReadOnly = 'read_only';

    /** Every permission an implemented route checks. @var array<string, string> */
    public const CATALOGUE = [
        'products.read' => 'عرض المنتجات',
        'products.create' => 'إنشاء منتج',
        'products.update' => 'تعديل منتج',
        'products.publish' => 'نشر منتج',
        'orders.read' => 'عرض الطلبات',
        'orders.refund' => 'استرداد مبالغ الطلبات',
        'customers.read' => 'عرض العملاء',
        'entitlements.revoke' => 'سحب صلاحيات الوصول',
        'search.synonyms.manage' => 'إدارة مرادفات البحث',
        'search.index.manage' => 'إدارة فهرس البحث',
        'analytics.read' => 'قراءة التحليلات',
        'audit.read' => 'قراءة سجل التدقيق',
        'appearance.manage' => 'إدارة المظهر والتيبوغرافيا',
        /*
         * محتوى الصفحة الرئيسة — صلاحيّةٌ مستقلّة لا امتدادٌ لـ`appearance.manage`.
         *
         * تلك فُتحت للسمة والتيبوغرافيا، ويحذّر تعليقها في `api-admin.php`
         * صراحةً من أنّها «امتدّت إلى ما لا يخصّها». والفرق حقيقيّ في متجرٍ
         * عامل: من يضبط رموز التصميم مصمّم، ومن يكتب عنوان الهيرو ونصّه
         * محرّر. ودمجُهما يُعطي المحرّر سلطةَ تغيير تصميم المتجر كلّه.
         */
        'content.manage' => 'تحرير محتوى الصفحة الرئيسة',
        'reviews.moderate' => 'إدارة المراجعات',
        'promotions.manage' => 'إدارة رموز الخصم',
        'users.manage' => 'إدارة مستخدمي اللوحة وأدوارهم',
        'notes.write' => 'كتابة ملاحظات على الطلبات والعملاء',
        /*
         * صلاحيّتان جديدتان، وكلتاهما تستوفي شرط هذا الملفّ: لكلٍّ نقطةُ
         * نهايةٍ تفرضها فعلًا (routes/api-admin.php — `operations/*`).
         *
         * وهما اثنتان لا واحدة لأنّهما دوران مختلفان في متجرٍ حقيقيّ: من
         * يعيد محاولة تسليمٍ ويقرّر في إرجاعٍ هو الدعم، ومن يُدخل بضاعةً
         * ويصحّح جردًا هو المستودع. دمجُهما يُعطي موظّف الدعم سلطةَ تعديل
         * المخزون — وهي أخطر ما في المتجر لأنّها تقرّر ما يُباع.
         */
        'operations.manage' => 'إدارة التنفيذ والشحن والإرجاع',
        'inventory.manage' => 'إدارة المخزون وحركاته',
        /*
         * إعادة تشغيل حدث دفعٍ فعلٌ ماليّ: قد تُنشئ دفعةً واستحقاقًا. فصلُها
         * عن `orders.refund` مقصود — من يردّ مبلغًا يعرف قيمته، ومن يعيد
         * تشغيل حدثٍ يعبث بسجلٍّ محاسبيّ وارد من طرفٍ ثالث.
         */
        'payments.manage' => 'إدارة أحداث الدفع وإعادة تشغيلها',
        /*
         * سعر الشحن قرارٌ تجاريّ يوميّ: قاعدةٌ خاطئة إمّا تخسر في كلّ طلبٍ
         * صامتةً وإمّا ترفض شحنًا ممكنًا. فصلُها عن `operations.manage`
         * مقصود — من ينقل شحنةً بين الحالات ليس من يسعّرها.
         */
        'shipping.manage' => 'إدارة مناطق الشحن وقواعده',
        /*
         * إحياء حدثٍ في صندوق الصادر يُعيد بثّه إلى **كلّ** مستهلكيه: رسالة،
         * وفهرس، ومشتقّات. أثرُه أوسع من أيّ فعلٍ تجاريّ مفرد، فلا يُطوى في
         * `operations.manage` التي يحملها موظّف الدعم.
         */
        'system.manage' => 'إحياء أحداث صندوق الصادر',
        /*
         * قاعدة تحويلٍ خاطئة تُسقط ترتيب صفحةٍ في نتائج البحث وتُضيع كلّ
         * زائرٍ يصل من رابطٍ قديم. هذا قرار SEO لا قرار تشغيل.
         */
        'redirects.manage' => 'إدارة قواعد التحويل',
        /*
         * مفاتيح المزوّدين — للمالك وحده، كـ`users.manage`.
         *
         * من يملك مفتاح بوّابة الدفع يملك تحويل مال الزبائن إلى حسابٍ آخر،
         * ومن يملك رمز وصول منصّةٍ اجتماعيّة ينشر باسم المتجر. هذه سلطةُ
         * حسابٍ لا سلطةُ تشغيل، فلا تُمنح لمن يدير المتجر يوميًّا.
         */
        'integrations.manage' => 'إدارة مفاتيح الربط مع المزوّدين',
        'url.read' => 'قراءة سجلّ العناوين وتفتيشها',
        'url.edit' => 'تغيير روابط الكيانات وقفلها',
        'slug.override' => 'تجاوز قفل الرابط المنشور',
    ];

    public function labelAr(): string
    {
        return match ($this) {
            self::Owner => 'المالك',
            self::Administrator => 'مدير النظام',
            self::ProductManager => 'مدير المنتجات',
            self::OrderManager => 'مدير الطلبات',
            self::SeoManager => 'مدير SEO',
            self::SearchManager => 'مدير البحث',
            self::Analytics => 'التحليلات',
            self::ReadOnly => 'قراءة فقط',
        };
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $all = array_keys(self::CATALOGUE);

        return match ($this) {
            self::Owner => $all,
            /*
             * The separation this comment used to promise is now real.
             *
             * Owner and Administrator were byte-identical while `users.manage`
             * had no endpoint to enforce it. It has one now, and only the Owner
             * holds it: an Administrator runs the whole store but cannot mint a
             * second Administrator, change anyone's role, or delete an account.
             *
             * That is the entire point of having two top roles — the account
             * that can grant power must be scarcer than the account that uses
             * it. Grant `users.manage` to Administrator and the two collapse
             * back into one role wearing two names.
             */
            self::Administrator => array_values(array_diff($all, ['users.manage', 'integrations.manage'])),
            self::ProductManager => [
                'products.read', 'products.create', 'products.update', 'products.publish',
                // المراجعة محتوى منتج: من ينشر المنتج ينشر ما يُقال عنه.
                'reviews.moderate',
                // والمخزون حقيقةُ منتج: من يملك الكتالوج يملك ما يُباع منه.
                'inventory.manage',
            ],
            self::OrderManager => [
                'orders.read', 'orders.refund', 'customers.read', 'entitlements.revoke', 'products.read',
                // الخصم قرار تجاري على الطلب: من يردّ مبلغًا يُصدر رمزًا.
                'promotions.manage',
                // ومن يعالج حالة يكتب ما جرى فيها: الملاحظة أداة تسليم المناوبة.
                'notes.write',
                /*
                 * التنفيذ امتدادُ الطلب لا نظامٌ آخر: من يردّ مبلغًا ويسحب
                 * استحقاقًا هو نفسه من يعيد محاولة تسليمٍ فشل ويقرّر في
                 * إرجاع. أمّا المخزون فلا — تعديلُه قرارٌ مستودعيّ.
                 */
                'operations.manage',
                // ومن يتابع دفعةً ضائعة هو من يتابع الطلب الذي ضاعت منه.
                'payments.manage',
                'shipping.manage',
            ],
            // مدير SEO يملك التحويلات: هي أداته الأولى في نقل المحتوى.
            self::SeoManager => ['products.read', 'analytics.read', 'redirects.manage', 'url.read', 'url.edit'],
            self::SearchManager => ['search.synonyms.manage', 'search.index.manage', 'analytics.read', 'products.read'],
            self::Analytics => ['analytics.read'],
            self::ReadOnly => ['products.read', 'orders.read', 'customers.read', 'analytics.read', 'audit.read'],
        };
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public static function labelForPermission(string $permission): string
    {
        return self::CATALOGUE[$permission] ?? $permission;
    }
}

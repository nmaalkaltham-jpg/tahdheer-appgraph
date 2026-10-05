<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * كل دور يصل فعلًا إلى ما تَعِد به صلاحياته — الفحص **الإيجابي**.
 *
 * ─── لماذا لم يكشف العيبَ اختبارٌ قائم ───
 *
 * كل اختبارات الإدارة كانت تتحقّق من الاتجاه السالب: «دورٌ بلا الصلاحية
 * يُرفض ٤٠٣». وقد كان يُرفض دائمًا — لكن ثلاثةً من الأدوار كانت تُرفض أيضًا
 * وهي مخوَّلة، لأنّ مجموعة `appearance.manage` في ملفّ المسارات كانت تلتهم
 * القسائم والمراجعات والطلب والزحف واستوديو المنتج.
 *
 * فالرفض الصحيح والرفض الخاطئ يبدوان متطابقَين من الخارج: ٤٠٣ و٤٠٣.
 * والفرق لا يظهر إلا حين يُسأل السؤال المعاكس: **هل يصل من يملك؟**
 *
 * ولذلك هذا الملفّ ليس اختبار انحدار لعيبٍ واحد، بل الاتجاه الغائب كلّه: كل
 * دور تشغيلي وأهمّ ما مُنح له. إضافة مجموعة مسارات جديدة تلتهم غيرها ستسقط
 * هنا فورًا.
 */
class RoutePermissionScopeTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    /** @return iterable<string, array{AdminRole, string}> */
    public static function grantedPaths(): iterable
    {
        yield 'مدير الطلبات ← القسائم' => [AdminRole::OrderManager, '/api/v1/admin/coupons'];
        yield 'مدير الطلبات ← الطلبات' => [AdminRole::OrderManager, '/api/v1/admin/orders'];
        yield 'مدير الطلبات ← العملاء' => [AdminRole::OrderManager, '/api/v1/admin/customers'];
        yield 'مدير المنتجات ← المراجعات' => [AdminRole::ProductManager, '/api/v1/admin/reviews'];
        yield 'مدير المنتجات ← المنتجات' => [AdminRole::ProductManager, '/api/v1/admin/products'];
        yield 'التحليلات ← الطلب' => [AdminRole::Analytics, '/api/v1/admin/demand'];
        yield 'التحليلات ← الزحف' => [AdminRole::Analytics, '/api/v1/admin/crawl'];
        yield 'مدير البحث ← المرادفات' => [AdminRole::SearchManager, '/api/v1/admin/search/synonyms'];
        yield 'مدير البحث ← الفهرس' => [AdminRole::SearchManager, '/api/v1/admin/search/index'];
        yield 'مدير SEO ← التحليلات' => [AdminRole::SeoManager, '/api/v1/admin/dashboard'];
        yield 'قراءة فقط ← التدقيق' => [AdminRole::ReadOnly, '/api/v1/admin/audit'];
    }

    #[DataProvider('grantedPaths')]
    public function test_a_role_reaches_what_its_permissions_promise(AdminRole $role, string $path): void
    {
        $this->actingAsAdmin($this->makeAdmin($role))
            ->getJson($path)
            ->assertOk();
    }

    /**
     * والاتجاه السالب يبقى مفروضًا: تصحيحُ التداخل لم يفتح شيئًا على مصراعيه.
     */
    public function test_appearance_routes_still_demand_appearance_manage(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager))
            ->getJson('/api/v1/admin/theme')
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'appearance.manage');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics))
            ->getJson('/api/v1/admin/navigation')
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'appearance.manage');
    }
}

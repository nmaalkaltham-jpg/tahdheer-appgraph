<?php

use App\Domain\Navigation\MenuTree;
use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\Models\NavigationRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * نقل تذييل المتجر من الشيفرة إلى القائمة المنشورة.
 *
 * كانت عشرون وجهةً مكتوبةً في `apps/storefront/components/SiteFooter.tsx`
 * (`COLUMNS`)، فموقع «التذييل» في استوديو القوائم يُنشر بنجاح ولا يغيّر حرفًا
 * عند زائر. وقد صار التذييل يقرأ المنشور — فلولا هذه الهجرة لخسر المتجر
 * العشرين رابطًا في اللحظة التي يُنشر فيها التغيير.
 *
 * الروابط منقولةٌ حرفيًّا عن `apps/storefront/lib/routes.ts` وقت النقل، لا
 * مخترَعة. والأعمدة الثلاثة صارت عقدًا جذريّة، ووجهاتُها أبناءها — وهو
 * بالضبط ما يصيّره `SiteFooter` الآن.
 *
 * خاملة: لا تفعل شيئًا إن كان للتذييل منشورٌ بالفعل. بيئةٌ حرّر فيها مشرفٌ
 * التذييل قبل الترقية لا يُسحق عملُه.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: list<array{0: string, 1: string}>}> */
    private const COLUMNS = [
        ['التسوّق', [
            ['كل المنتجات', '/shop'],
            ['المراحل التعليمية', '/stages'],
            ['الصفوف الدراسية', '/grades'],
            ['المواد الدراسية', '/subjects'],
            ['الفصول الدراسية', '/semesters'],
            ['أنواع الملفات', '/kinds'],
            ['العروض الحالية', '/offers'],
            ['الأكثر مبيعًا', '/best-sellers'],
            ['وصل حديثًا', '/new'],
        ]],
        ['روابط مهمة', [
            ['من نحن', '/about'],
            ['المدوّنة', '/blog'],
            ['تواصل معنا', '/contact'],
            ['اطلب ملفًا غير متوفّر', '/request'],
            ['كيف نعمل', '/how-it-works'],
            ['طرق الدفع', '/payment-methods'],
        ]],
        ['السياسات', [
            ['سياسة الخصوصية', '/policies/privacy'],
            ['الشروط والأحكام', '/policies/terms'],
            ['سياسة الاسترجاع', '/policies/refund'],
            ['اتفاقية الترخيص', '/policies/license'],
            ['ملفات تعريف الارتباط', '/policies/cookies'],
        ]],
    ];

    public function up(): void
    {
        $existing = NavigationMenu::query()->where('location', 'footer')->first();

        if ($existing !== null && $existing->published_at !== null) {
            return; // منشورٌ بالفعل — لا يُسحق.
        }

        $raw = [];
        $column = 0;

        foreach (self::COLUMNS as [$title, $links]) {
            $column++;
            $parentId = MenuTree::ID_PREFIX.'fcol'.$column;

            // العمود عقدةٌ جذريّة: عنوانه هو `label`، ووجهته صفحة المتجر الأولى فيه.
            $raw[] = [
                'id' => $parentId,
                'parentId' => null,
                'label' => $title,
                'url' => $links[0][1],
                'type' => 'custom',
            ];

            $index = 0;
            foreach ($links as [$label, $url]) {
                $index++;
                $raw[] = [
                    'id' => $parentId.'i'.$index,
                    'parentId' => $parentId,
                    'label' => $label,
                    'url' => $url,
                    'type' => 'custom',
                ];
            }
        }

        $nodes = MenuTree::normalize($raw);

        DB::transaction(function () use ($existing, $nodes): void {
            $menu = $existing ?? new NavigationMenu;
            $menu->forceFill([
                'location' => 'footer',
                'name' => MenuTree::LOCATIONS['footer'],
                'draft_nodes' => $nodes,
                'published_nodes' => $nodes,
                'version' => 1,
                'published_version' => 1,
                'published_at' => now(),
            ])->save();

            /*
             * الإصدار يُكتب أيضًا: بدونه لا لقطةَ يعود إليها تراجعٌ لاحق،
             * فيصير أوّل تعديلٍ على التذييل غير قابلٍ للرجوع.
             */
            NavigationRevision::query()->updateOrCreate(
                ['menu_id' => $menu->id, 'revision' => 1],
                [
                    'nodes' => $nodes,
                    'fingerprint' => MenuTree::fingerprint($nodes),
                    'node_count' => count($nodes),
                    'created_at' => now(),
                ],
            );
        });
    }

    public function down(): void
    {
        // لا عكس: حذف التذييل المنشور يترك المتجر بلا روابطه، وهو أسوأ من بقائه.
    }
};

<?php

use App\Domain\Navigation\MenuTree;
use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\Models\NavigationRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * نقل ترويسة المتجر من الشيفرة إلى القائمة المنشورة — مرآة هجرة التذييل.
 *
 * كانت خمس وجهاتٍ مكتوبةً في `SiteHeaderClient.tsx` (`NAV_ITEMS`) تُصيَّر
 * كلّما كانت القائمة المنشورة فارغة. حُذفت مع الاحتياطيّ المكتوب، فبيئةٌ لم
 * تُنشر فيها الترويسة قطّ كانت ستعرض ملاحةً بلا روابط حيث كان المتجر يعرض
 * خمسًا تعمل. الخمس تُنشر هنا مرّةً واحدة، حرفيًّا عن `lib/routes.ts`.
 *
 * خاملة: لا تفعل شيئًا إن كان للترويسة منشورٌ بالفعل — بيئة التطوير نشرت
 * ترويستها من الاستوديو ولا يُسحق ما نُشر منه.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string}> */
    private const LINKS = [
        ['الرئيسية', '/'],
        ['المراحل التعليمية', '/stages'],
        ['المتجر', '/shop'],
        ['المدونة', '/blog'],
        ['تواصل معنا', '/contact'],
    ];

    public function up(): void
    {
        $existing = NavigationMenu::query()->where('location', 'header')->first();

        if ($existing !== null && $existing->published_at !== null) {
            return;
        }

        $raw = [];
        foreach (self::LINKS as $i => [$label, $url]) {
            $raw[] = ['id' => MenuTree::ID_PREFIX.'hdr'.($i + 1), 'parentId' => null, 'label' => $label, 'url' => $url, 'type' => 'custom'];
        }
        $nodes = MenuTree::normalize($raw);

        DB::transaction(function () use ($existing, $nodes): void {
            $menu = $existing ?? new NavigationMenu;
            $menu->forceFill([
                'location' => 'header',
                'name' => MenuTree::LOCATIONS['header'],
                'draft_nodes' => $nodes,
                'published_nodes' => $nodes,
                'version' => 1,
                'published_version' => 1,
                'published_at' => now(),
            ])->save();

            NavigationRevision::query()->updateOrCreate(
                ['menu_id' => $menu->id, 'revision' => 1],
                ['nodes' => $nodes, 'fingerprint' => MenuTree::fingerprint($nodes), 'node_count' => count($nodes), 'created_at' => now()],
            );
        });
    }

    public function down(): void
    {
        // لا عكس: حذف الترويسة المنشورة يترك المتجر بلا روابطه.
    }
};

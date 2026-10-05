<?php

declare(strict_types=1);

namespace App\Domain\Crawl;

use App\Domain\Crawl\Models\CrawledPage;
use App\Domain\Crawl\Models\CrawlRun;
use App\Domain\Crawl\Models\InternalLink;
use App\Domain\SEO\SiteIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * زاحف الموقع الداخلي — يبني رسم الروابط وعمق النقر ويكشف اليتامى.
 *
 * يزحف على **هذا المتجر وحده**. ليس زاحفًا عامًّا: البذور من الرئيسية وخريطة
 * الموقع، والحدود المسموح بتتبّعها هي مسارات هذا الأصل لا غير. أي عنوان خارج
 * الأصل يُسجَّل حافةً ولا يُزار — الزحف إلى عناوين يقرّرها محتوى صفحة هو
 * تزوير طلب من الخادم بثوب آخر.
 *
 * ثلاث حمايات ليست تحسينًا:
 *  · **حدّ للصفحات ومهلة لكل طلب.** موقع بحلقة تصفية لا نهائية يجعل الزاحف
 *    يعمل إلى الأبد ويأكل موارد الدفع والسلة.
 *  · **إسقاط معاملات الاستعلام من الهوية.** ‎/shop?page=2&utm=x‎ ليس عنوانًا
 *    جديدًا في رسم الروابط؛ اعتباره كذلك يفجّر الحدود بلا معلومة.
 *  · **الأولوية للعمق لا للاكتشاف.** الطابور بعرض أوّلًا (BFS) لأن عمق النقر
 *    **هو** أقصر مسافة — عمقٌ يُحسب بمسار عميق يُبلغ عن دفن لم يقع.
 */
final class SiteCrawler
{
    /** سقف الصفحات في الجولة الواحدة. */
    private const MAX_PAGES = 400;

    private const TIMEOUT = 8;

    /** امتدادات لا تُزار: أصول لا صفحات. */
    private const SKIP_EXTENSIONS = ['.css', '.js', '.png', '.jpg', '.jpeg', '.webp', '.avif', '.svg', '.ico', '.pdf', '.xml', '.txt', '.zip', '.woff', '.woff2'];

    /** بادئات لا تُزار: خاصّة أو بلا قيمة اكتشاف. */
    private const SKIP_PREFIXES = ['/api/', '/_next/', '/cart', '/checkout', '/account', '/orders', '/downloads', '/files', '/pay', '/login'];

    public function __construct(private readonly SiteIdentity $site) {}

    /** جولة زحف كاملة: بذور → BFS → رسم → عمق → يتامى. */
    public function crawl(?int $maxPages = null): CrawlRun
    {
        $origin = $this->site->origin();

        if ($origin === null) {
            // بلا أصل مضبوط لا يُعرف ما هو «داخلي» — والزحف بلا حدّ داخلي خطر.
            throw new \RuntimeException('أصل المتجر غير مضبوط: لا يمكن تمييز الرابط الداخلي من الخارجي.');
        }

        $limit = max(1, min($maxPages ?? self::MAX_PAGES, self::MAX_PAGES));
        $run = CrawlRun::query()->create(['status' => 'running', 'started_at' => now()]);

        try {
            $this->walk($run, $origin, $limit);
            $this->computeGraph($run);

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'failure' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        return $run->refresh();
    }

    /**
     * مرحلتان: BFS من الرئيسية يمنح العمق، ثم بذور الخريطة بلا عمق.
     *
     * لماذا لا تُبذَر الخريطة في BFS نفسه: كانت تأخذ عمق 0 فتُقرأ «على بُعد
     * نقرة من الرئيسية» وهي قد لا تكون موصولة برابط واحد — وهو عكس الحقيقة،
     * ويُبطل الإشارة كلّها. عمق النقر يُقاس بالروابط لا بالعضوية في ملفّ.
     * `null` تعني «لم يُوصَل إليها»، وهي الجواب الصادق.
     */
    private function walk(CrawlRun $run, string $origin, int $limit): void
    {
        /** @var array<string, int|null> المسار ⇒ عمقه (null = غير موصول) */
        $queued = ['/' => 0];
        $queue = [['/', 0, 'home']];

        $sitemapOnly = [];
        foreach ($this->seeds($origin) as $path => $source) {
            if ($source === 'sitemap' && $path !== '/') {
                $sitemapOnly[$path] = true;
            }
        }

        $visited = 0;
        $links = [];

        while ($queue !== [] && $visited < $limit) {
            [$path, $depth, $source] = array_shift($queue);
            $visited++;

            $page = $this->fetch($origin, $path);

            CrawledPage::query()->create([
                'run_id' => $run->id,
                'path' => $path,
                'status' => $page['status'],
                'title' => $page['title'],
                'canonical' => $page['canonical'],
                'robots' => $page['robots'],
                'redirect_to' => $page['redirectTo'],
                'discovered_from' => $source,
                'crawled_at' => now(),
            ]);

            foreach ($page['links'] as $link) {
                $links[] = [
                    'run_id' => $run->id,
                    'from_path' => $path,
                    'to_path' => $link['to'],
                    'anchor' => $link['anchor'],
                    'placement' => $link['placement'],
                    'nofollow' => $link['nofollow'],
                ];

                /*
                 * العمق يُثبَّت عند أوّل وصول: BFS يضمن أنه الأقصر.
                 * والعمق يُورَّث فقط من عقدة موصولة — ابن عقدة غير موصولة
                 * يبقى غير موصول، لا «عمق null + 1».
                 */
                if (! array_key_exists($link['to'], $queued) && $this->isCrawlable($link['to'])) {
                    $childDepth = $depth === null ? null : $depth + 1;
                    $queued[$link['to']] = $childDepth;
                    $queue[] = [$link['to'], $childDepth, 'link'];
                    unset($sitemapOnly[$link['to']]);
                }
            }

            if (count($links) >= 500) {
                InternalLink::query()->insert($links);
                $links = [];
            }
        }

        /*
         * المرحلة الثانية: ما في الخريطة ولم يصله رابط. يُزار ليُعرف رمز
         * حالته وتُسجَّل حوافه، ويبقى عمقه `null` — وهو تعريف اليتيم.
         */
        foreach (array_keys($sitemapOnly) as $path) {
            if ($visited >= $limit) {
                break;
            }
            $visited++;
            $queued[$path] = null;

            $page = $this->fetch($origin, $path);

            CrawledPage::query()->create([
                'run_id' => $run->id,
                'path' => $path,
                'status' => $page['status'],
                'title' => $page['title'],
                'canonical' => $page['canonical'],
                'robots' => $page['robots'],
                'redirect_to' => $page['redirectTo'],
                'discovered_from' => 'sitemap',
                'crawled_at' => now(),
            ]);

            foreach ($page['links'] as $link) {
                $links[] = [
                    'run_id' => $run->id,
                    'from_path' => $path,
                    'to_path' => $link['to'],
                    'anchor' => $link['anchor'],
                    'placement' => $link['placement'],
                    'nofollow' => $link['nofollow'],
                ];
            }
        }

        if ($links !== []) {
            InternalLink::query()->insert($links);
        }

        // العمق يُكتب بعد المرور كلّه: الصفحة قد تُكتشَف قبل أن تُزار.
        foreach ($queued as $path => $depth) {
            CrawledPage::query()
                ->where('run_id', $run->id)
                ->where('path', $path)
                ->update(['depth' => $depth]);
        }
    }

    /**
     * البذور: الرئيسية وخريطة الموقع.
     *
     * الخريطة بذرةً لا حَكمًا: عنوان فيها لا يشير إليه رابط واحد هو **بالضبط**
     * اليتيم الذي نبحث عنه — لولا البذر منها لما زرناه ولما عرفنا أنه يتيم.
     *
     * @return array<string, string> المسار ⇒ مصدر الاكتشاف
     */
    private function seeds(string $origin): array
    {
        $seeds = ['/' => 'home'];

        try {
            $xml = Http::timeout(self::TIMEOUT)->get($origin.'/sitemap.xml');

            if ($xml->successful()) {
                preg_match_all('#<loc>([^<]+)</loc>#', $xml->body(), $m);
                foreach ($m[1] ?? [] as $loc) {
                    $path = $this->toInternalPath($origin, html_entity_decode($loc));
                    if ($path !== null && ! isset($seeds[$path])) {
                        $seeds[$path] = 'sitemap';
                    }
                }
            }
        } catch (ConnectionException) {
            // خريطة متعذّرة لا توقف الزحف: الرئيسية وحدها بذرة صالحة.
        }

        return $seeds;
    }

    /**
     * @return array{status: int|null, title: ?string, canonical: ?string, robots: ?string, redirectTo: ?string, links: list<array{to: string, anchor: ?string, placement: string, nofollow: bool}>}
     */
    private function fetch(string $origin, string $path): array
    {
        $empty = ['status' => null, 'title' => null, 'canonical' => null, 'robots' => null, 'redirectTo' => null, 'links' => []];

        try {
            $response = Http::withoutRedirecting()
                ->timeout(self::TIMEOUT)
                ->withHeaders(['user-agent' => 'TahdheerCrawler/1.0 (internal)'])
                ->get($origin.$path);
        } catch (ConnectionException) {
            return $empty;
        }

        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            return [...$empty, 'status' => $status, 'redirectTo' => $response->header('location') ?: null];
        }

        if ($status >= 400) {
            return [...$empty, 'status' => $status];
        }

        $html = $response->body();

        return [
            'status' => $status,
            'title' => $this->firstMatch('#<title[^>]*>(.*?)</title>#is', $html, 300),
            'canonical' => $this->firstMatch('#<link[^>]+rel="canonical"[^>]*href="([^"]+)"#i', $html, 500),
            'robots' => $this->firstMatch('#<meta[^>]+name="robots"[^>]*content="([^"]+)"#i', $html, 120),
            'redirectTo' => null,
            'links' => $this->extractLinks($origin, $html),
        ];
    }

    /**
     * روابط الصفحة الداخلية بنصوص مراسيها.
     *
     * @return list<array{to: string, anchor: ?string, placement: string, nofollow: bool}>
     */
    private function extractLinks(string $origin, string $html): array
    {
        // موضع الرابط يُستنتج من أقرب معلَم بنيوي قبله — التذييل ليس المتن.
        $footerAt = stripos($html, '<footer');
        $headerEnd = stripos($html, '</header>');

        $out = [];
        $seen = [];

        preg_match_all('#<a\b([^>]*)>(.*?)</a>#is', $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attrs = $match[1][0];
            $offset = $match[0][1];

            if (preg_match('#href="([^"]*)"#i', $attrs, $href) !== 1) {
                continue;
            }

            $to = $this->toInternalPath($origin, html_entity_decode($href[1]));
            if ($to === null) {
                continue;
            }

            $anchor = trim(preg_replace('/\s+/u', ' ', strip_tags($match[2][0])) ?? '');
            $placement = match (true) {
                $footerAt !== false && $offset > $footerAt => 'footer',
                $headerEnd !== false && $offset < $headerEnd => 'header',
                default => 'body',
            };

            // حافة واحدة لكل (وجهة، موضع): قائمة تتكرّر في كل صفحة لا تُضخَّم.
            $key = $to.'|'.$placement;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'to' => $to,
                'anchor' => $anchor === '' ? null : mb_substr($anchor, 0, 300),
                'placement' => $placement,
                'nofollow' => stripos($attrs, 'nofollow') !== false,
            ];
        }

        return $out;
    }

    /** المسار الداخلي المطبَّع، أو null لأي شيء خارج هذا الأصل. */
    private function toInternalPath(string $origin, string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            if (! str_starts_with($href, $origin.'/') && $href !== $origin) {
                return null;
            }
            $href = substr($href, strlen($origin)) ?: '/';
        } elseif (str_starts_with($href, '//')) {
            // بروتوكول-نسبي: يبدو داخليًّا ويخرج من الموقع.
            return null;
        } elseif (! str_starts_with($href, '/')) {
            return null;
        }

        // الهوية بلا استعلام ولا مرساة: ?page=2 ليس عنوانًا جديدًا في الرسم.
        $path = strtok($href, '?#') ?: '/';
        $path = rawurldecode($path);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : mb_substr($path, 0, 500);
    }

    private function isCrawlable(string $path): bool
    {
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        foreach (self::SKIP_EXTENSIONS as $ext) {
            if (str_ends_with(strtolower($path), $ext)) {
                return false;
            }
        }

        return true;
    }

    /** يحسب الدرجات ويلخّص الجولة بعد اكتمال المرور. */
    private function computeGraph(CrawlRun $run): void
    {
        // الروابط الواردة والصادرة — بعدّ الحواف لا بتقدير.
        DB::table('crawled_pages')->where('run_id', $run->id)->update([
            'inbound' => DB::raw('(select count(*) from internal_links l where l.run_id = '.$run->id.' and l.to_path = crawled_pages.path and l.from_path <> crawled_pages.path)'),
            'outbound' => DB::raw('(select count(*) from internal_links l where l.run_id = '.$run->id.' and l.from_path = crawled_pages.path)'),
        ]);

        $pages = CrawledPage::query()->where('run_id', $run->id);

        $run->forceFill([
            'pages' => (clone $pages)->count(),
            'links' => InternalLink::query()->where('run_id', $run->id)->count(),
            'broken' => (clone $pages)->where('status', '>=', 400)->count(),
            /*
             * اليتيم: صفحة تُجيب 200 ولا يشير إليها رابط واحد. الرئيسية
             * مستثناة — هي المدخل، ولا يُفترض أن يشير إليها شيء لتُكتشَف.
             */
            'orphans' => (clone $pages)->where('status', 200)->where('inbound', 0)->where('path', '!=', '/')->count(),
            'max_depth' => (int) ((clone $pages)->max('depth') ?? 0),
        ])->save();
    }

    private function firstMatch(string $pattern, string $subject, int $max): ?string
    {
        if (preg_match($pattern, $subject, $m) !== 1) {
            return null;
        }

        $value = trim(html_entity_decode(strip_tags($m[1])));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}

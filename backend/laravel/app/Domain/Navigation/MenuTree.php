<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

/**
 * عقد شجرة القائمة وتطبيعها.
 *
 * الشجرة تصل من اللوحة قائمةً مسطّحة بمعرّفات وأبوَين. التطبيع هنا يفرض ما لا
 * تستطيع الواجهة ضمانه:
 *
 *  · **لا دورات.** عنصر أبوه حفيده يجعل التصيير يستدعي نفسه إلى أن ينفد
 *    المكدّس. تُقطع العلاقة ويُرفع العنصر إلى الجذر بدل رفض الحفظ كلّه.
 *  · **لا عمق بلا حدّ.** أربعة مستويات كما تعلنه اللوحة؛ ما زاد يُرفع.
 *  · **لا معرّفات مكرّرة.** المكرّر يُسقَط: مفتاحان متطابقان في React
 *    يُنتجان تصييرًا غير محدَّد.
 *  · **لا `javascript:` ولا `data:`.** رابط قائمة يصل سمة href مباشرة، وهو
 *    مسار تنفيذ نصوص عبر المواقع لو قُبل أي مخطَّط.
 */
final class MenuTree
{
    public const MAX_DEPTH = 4;

    public const MAX_NODES = 200;

    /** @var list<string> */
    public const TYPES = ['page', 'product', 'category', 'custom'];

    /** @var list<string> */
    public const TARGETS = ['_self', '_blank'];

    /**
     * الأماكن التي يعرف المتجر كيف يعرض قائمةً فيها — ولكلٍّ مُصيِّر قائم.
     *
     * `mobile` ليس تكرارًا للترويسة: لوح الإفصاح في الجوّال عقدة منفصلة في
     * DOM ويقبل قائمةً أقصر. غيابه منشورًا يعني «اتبع الترويسة» لا «لا قائمة».
     */
    public const LOCATIONS = [
        'header' => 'الترويسة',
        'mobile' => 'قائمة الجوّال',
        'footer' => 'التذييل',
    ];

    /** الموقع الذي يرثه mobile حين لا يُنشر له شيء. */
    public const FALLS_BACK_TO = ['mobile' => 'header'];

    /** بادئة المعرّف الذي يمنحه الخادم — يميّزه عن `tmp_` القادم من المتصفّح. */
    public const ID_PREFIX = 'n_';

    /**
     * يمنح معرّفات دائمة لكل عقدة مؤقّتة، ويعيد ربط الأبناء بها.
     *
     * `tmp_3` معرّف عدّاد في متصفّح واحد: يتصادم بين محرّرَين، ويتغيّر معناه
     * بين جلستين، ولا يصلح هوية دائمة. الخادم يمنح البديل ويعيد الخريطة
     * ليستبدلها المحرّر في حالته — وإلّا بقي يحرّر عقدًا بأسماء ماتت.
     *
     * @param  list<array<string, mixed>>  $nodes  عقد مُطبَّعة
     * @return array{nodes: list<array<string, mixed>>, idMap: array<string, string>}
     */
    public static function assignStableIds(array $nodes): array
    {
        $idMap = [];

        foreach ($nodes as $node) {
            $id = (string) $node['id'];
            if (! str_starts_with($id, self::ID_PREFIX)) {
                // عشوائية مشفّرة لا عدّاد: العدّاد يتصادم بين محرّرَين متزامنَين.
                $idMap[$id] = self::ID_PREFIX.bin2hex(random_bytes(6));
            }
        }

        if ($idMap === []) {
            return ['nodes' => $nodes, 'idMap' => []];
        }

        $remapped = [];
        foreach ($nodes as $node) {
            $node['id'] = $idMap[$node['id']] ?? $node['id'];
            if ($node['parentId'] !== null) {
                $node['parentId'] = $idMap[$node['parentId']] ?? $node['parentId'];
            }
            $remapped[] = $node;
        }

        return ['nodes' => $remapped, 'idMap' => $idMap];
    }

    /**
     * بصمة التمثيل المعياري.
     *
     * الترتيب والمفاتيح مثبّتان قبل التجزئة: بصمة تعتمد ترتيب مفاتيح JSON
     * تتغيّر بلا تغيّر معنى، فتُبلغ عن انحراف لم يقع وتُفشل تراجعًا سليمًا.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    public static function fingerprint(array $nodes): string
    {
        $canonical = array_map(static function (array $node): array {
            ksort($node);
            if (isset($node['hide']) && is_array($node['hide'])) {
                ksort($node['hide']);
            }

            return $node;
        }, $nodes);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * فرق بين شجرتين — للتدقيق ولعرض «ما الذي سيتغيّر» قبل النشر.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{created: int, updated: int, deleted: int, reordered: int, unchanged: int}
     */
    public static function diff(array $before, array $after): array
    {
        $beforeById = array_column($before, null, 'id');
        $afterById = array_column($after, null, 'id');
        $beforeOrder = array_flip(array_column($before, 'id'));
        $afterOrder = array_flip(array_column($after, 'id'));

        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'reordered' => 0, 'unchanged' => 0];

        foreach ($afterById as $id => $node) {
            if (! isset($beforeById[$id])) {
                $counts['created']++;

                continue;
            }
            if ($beforeById[$id] !== $node) {
                $counts['updated']++;
            } elseif (($beforeOrder[$id] ?? null) !== ($afterOrder[$id] ?? null)) {
                // نفس المحتوى وموضع مختلف: إعادة ترتيب لا تعديل.
                $counts['reordered']++;
            } else {
                $counts['unchanged']++;
            }
        }

        $counts['deleted'] = count(array_diff_key($beforeById, $afterById));

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $clean = [];
        $seen = [];

        foreach (array_slice($input, 0, self::MAX_NODES) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $id = self::slug($raw['id'] ?? null, 64);
            if ($id === null || isset($seen[$id])) {
                continue;
            }

            $label = self::text($raw['label'] ?? null, 120);
            $url = self::url($raw['url'] ?? null);
            // عنصر بلا نصّ أو بلا وجهة لا يُصيَّر إلا فراغًا قابلًا للنقر.
            if ($label === null || $url === null) {
                continue;
            }

            $seen[$id] = true;
            $type = (string) ($raw['type'] ?? 'custom');
            $target = (string) ($raw['target'] ?? '_self');

            $clean[$id] = [
                'id' => $id,
                'parentId' => self::slug($raw['parentId'] ?? null, 64),
                'label' => $label,
                'url' => $url,
                'type' => in_array($type, self::TYPES, true) ? $type : 'custom',
                'target' => in_array($target, self::TARGETS, true) ? $target : '_self',
                'rel' => self::relFor($raw['rel'] ?? null, $target),
                'classes' => self::text($raw['classes'] ?? null, 120) ?? '',
                'cssId' => self::slug($raw['cssId'] ?? null, 64) ?? '',
                'aria' => self::text($raw['aria'] ?? null, 160) ?? '',
                'desc' => self::text($raw['desc'] ?? null, 200) ?? '',
                'badge' => self::text($raw['badge'] ?? null, 40) ?? '',
                'icon' => self::slug($raw['icon'] ?? null, 40) ?? '',
                'sourceId' => is_int($raw['sourceId'] ?? null) ? $raw['sourceId'] : null,
                'sourceApi' => self::text($raw['sourceApi'] ?? null, 120),
            ];
        }

        return self::reparent($clean);
    }

    /**
     * يصحّح الأبوّة: أب مفقود أو دورة أو عمق زائد ⇒ رفع إلى الجذر.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function reparent(array $nodes): array
    {
        foreach ($nodes as $id => $node) {
            $parent = $node['parentId'];

            if ($parent === null) {
                continue;
            }

            if (! isset($nodes[$parent]) || $parent === $id) {
                $nodes[$id]['parentId'] = null;

                continue;
            }

            // تسلّق السلسلة: الوصول إلى النفس دورة، وتجاوز الحدّ عمق زائد.
            $depth = 0;
            $cursor = $parent;
            $cycle = false;

            while ($cursor !== null && $depth <= self::MAX_DEPTH) {
                if ($cursor === $id) {
                    $cycle = true;
                    break;
                }
                $cursor = $nodes[$cursor]['parentId'] ?? null;
                $depth++;
            }

            if ($cycle || $depth >= self::MAX_DEPTH) {
                $nodes[$id]['parentId'] = null;
            }
        }

        return array_values($nodes);
    }

    /**
     * `rel` مضبوط لا حرّ: فتح في تبويب جديد بلا `noopener` يمنح الصفحة
     * الهدف مرجعًا إلى نافذتنا عبر `window.opener`.
     */
    private static function relFor(mixed $raw, string $target): string
    {
        $parts = [];
        foreach (preg_split('/\s+/', is_string($raw) ? trim($raw) : '') ?: [] as $token) {
            if (in_array($token, ['nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc'], true)) {
                $parts[] = $token;
            }
        }

        if ($target === '_blank') {
            $parts[] = 'noopener';
            $parts[] = 'noreferrer';
        }

        return implode(' ', array_values(array_unique($parts)));
    }

    /** رابط داخلي أو http(s) مطلق. أي مخطَّط آخر يُرفَض. */
    private static function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim($value);
        if ($clean === '' || mb_strlen($clean) > 500) {
            return null;
        }

        if (str_starts_with($clean, '/')) {
            // «//host» رابط بروتوكول-نسبي يخرج من الموقع رغم بدئه بشرطة.
            return str_starts_with($clean, '//') ? null : $clean;
        }

        if (str_starts_with($clean, '#')) {
            return $clean;
        }

        $scheme = strtolower((string) parse_url($clean, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $clean : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    private static function slug(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? '';

        return $clean === '' ? null : substr($clean, 0, $max);
    }
}

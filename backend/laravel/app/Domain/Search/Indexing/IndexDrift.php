<?php

declare(strict_types=1);

namespace App\Domain\Search\Indexing;

use App\Domain\Search\Providers\MeilisearchProvider;
use App\Models\Product;

/**
 * هل الفهرس مرآةُ القاعدة؟ كلُّ منتجٍ منشور: وثيقتُه المتوقَّعة (`documentFor`)
 * مقابل ما في Meilisearch — ناقص، أو يتيم (في الفهرس لا في القاعدة)، أو قديمٌ
 * في حقلٍ يُرشَّح به أو يُرتَّب أو يُطابَق عليه.
 */
final class IndexDrift
{
    /** الحقول التي يغيّر انحرافُها الجواب. */
    public const FIELDS = ['title', 'sku', 'stage', 'grade', 'subject', 'semester', 'resource_type', 'format', 'price_minor', 'in_stock', 'nature', 'synonyms', 'extracted_content', 'editable', 'published_at'];

    public function __construct(private readonly MeilisearchProvider $meili) {}

    /**
     * @return array{index: string, published: int, indexed: int, missing: list<int>, orphans: list<int>, stale: array<string, list<int>>, staleIds: list<int>}
     */
    public function measure(): array
    {
        $uid = $this->meili->indexName();
        $indexed = [];
        foreach ($this->meili->documents($uid) as $doc) {
            $indexed[(int) $doc['id']] = $doc;
        }

        $expected = [];
        Product::query()->published()->with(['price', 'seo', 'stage', 'grade', 'subject', 'semester'])
            ->chunkById(200, function ($products) use (&$expected) {
                foreach ($products as $p) {
                    $expected[(int) $p->id] = $this->meili->documentFor($p);
                }
            });

        $stale = [];
        foreach (array_intersect_key($expected, $indexed) as $id => $doc) {
            foreach (self::FIELDS as $field) {
                if ($this->norm($doc[$field] ?? null) !== $this->norm($indexed[$id][$field] ?? null)) {
                    $stale[$field][] = $id;
                }
            }
        }
        ksort($stale);

        return [
            'index' => $uid,
            'published' => count($expected),
            'indexed' => count($indexed),
            'missing' => array_values(array_map('intval', array_keys(array_diff_key($expected, $indexed)))),
            'orphans' => array_values(array_map('intval', array_keys(array_diff_key($indexed, $expected)))),
            'stale' => $stale,
            'staleIds' => array_values(array_unique(array_merge(...array_values($stale) ?: [[]]))),
        ];
    }

    private function norm(mixed $v): string
    {
        if (is_array($v)) {
            sort($v);
        }

        return is_float($v) ? (string) round($v, 4) : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}

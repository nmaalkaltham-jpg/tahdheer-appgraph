<?php

namespace App\Domain\Catalog;

use App\Domain\Search\ArabicNormalizer;
use App\Models\CurriculumAlias;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;

/**
 * A new product classifies itself — port of the legacy
 * inc/curriculum/classify.php rules:
 *
 *  - normalize title (+ short copy) with the SAME normalizer search uses;
 *  - longest-alias-first WHOLE-WORD match, so «الدراسات الإسلامية» wins over «إسلامية»
 *    and «أول» never captures «أولى»;
 *  - a dimension the merchant has already set by hand is NEVER touched;
 *  - «الصف الثالث الابتدائي» states the stage too — grade ⇒ stage inference.
 */
class ProductClassifier
{
    /** kind and subject may legitimately be multi-valued; the rest are singular. */
    private const MULTI = ['kind', 'subject'];

    /** @var array<string, array<int, array{entity_id: int, phrase: string, len: int}>> */
    private array $vocabulary = [];

    /**
     * Normalise text for matching, space-padded so a whole-word test can look
     * for a surrounding space at either end.
     */
    public static function norm(string $text): string
    {
        $text = ArabicNormalizer::normalize($text);
        $text = ArabicNormalizer::normalizeDigits($text);

        return ' '.((string) preg_replace('/\s+/u', ' ', trim($text))).' ';
    }

    /**
     * Which dimensions does this text name?
     *
     * @return array{stage: ?int, grade: ?int, semester: ?int, subject: array<int, int>, kind: array<int, int>}
     */
    public function read(string $text): array
    {
        $hay = self::norm($text);

        $out = [
            'stage' => $this->matchOne('stage', $hay),
            'grade' => $this->matchOne('grade', $hay),
            'semester' => $this->matchOne('semester', $hay),
            'subject' => $this->matchMany('subject', $hay),
            'kind' => $this->matchMany('kind', $hay),
        ];

        // Grade ⇒ stage inference when the stage was not stated explicitly.
        if ($out['stage'] === null && $out['grade'] !== null) {
            $out['stage'] = Grade::query()->whereKey($out['grade'])->value('stage_id');
        }

        return $out;
    }

    /**
     * Fill in whatever dimensions a product is still missing. Never replaces a
     * dimension already set: once a merchant has chosen, the choice stands.
     *
     * @return array<string, mixed> The attributes actually written.
     */
    public function apply(Product $product, ?string $extraText = null): array
    {
        $text = trim($product->title.' '.($extraText ?? $product->short_desc ?? ''));
        $reading = $this->read($text);

        $written = [];

        foreach (['stage_id' => 'stage', 'grade_id' => 'grade', 'semester_id' => 'semester'] as $column => $dim) {
            if ($product->{$column} === null && $reading[$dim] !== null) {
                $written[$column] = $reading[$dim];
            }
        }

        // products carry a single subject FK: the first (longest-alias) match wins.
        if ($product->subject_id === null && $reading['subject'] !== []) {
            $written['subject_id'] = $reading['subject'][0];
        }

        // Kind lands on resource_type (the product-level enum), same no-overwrite rule.
        if ($product->resource_type === null && $reading['kind'] !== []) {
            $kindName = Kind::query()->whereKey($reading['kind'][0])->value('name_ar');
            if ($kindName !== null) {
                $written['resource_type'] = $kindName;
            }
        }

        if ($written !== []) {
            $product->fill($written)->save();
        }

        return $written;
    }

    private function matchOne(string $entityType, string $haystack): ?int
    {
        foreach ($this->vocabulary($entityType) as $entry) {
            if (mb_strpos($haystack, ' '.$entry['phrase'].' ') !== false) {
                return $entry['entity_id']; // vocabulary is sorted longest-first
            }
        }

        return null;
    }

    /** @return array<int, int> */
    private function matchMany(string $entityType, string $haystack): array
    {
        $hits = [];
        foreach ($this->vocabulary($entityType) as $entry) {
            if (mb_strpos($haystack, ' '.$entry['phrase'].' ') !== false) {
                $hits[$entry['entity_id']] = true;
            }
        }

        return array_keys($hits);
    }

    /**
     * The searchable vocabulary of one dimension, longest phrase first so the
     * most specific vocabulary wins the match.
     *
     * @return array<int, array{entity_id: int, phrase: string, len: int}>
     */
    private function vocabulary(string $entityType): array
    {
        if (! isset($this->vocabulary[$entityType])) {
            $rows = CurriculumAlias::query()
                ->where('entity_type', $entityType)
                ->get(['entity_id', 'normalized_alias'])
                ->map(fn ($row) => [
                    'entity_id' => (int) $row->entity_id,
                    'phrase' => $row->normalized_alias,
                    'len' => mb_strlen($row->normalized_alias),
                ])
                ->sortByDesc('len')
                ->values()
                ->all();

            $this->vocabulary[$entityType] = $rows;
        }

        return $this->vocabulary[$entityType];
    }
}

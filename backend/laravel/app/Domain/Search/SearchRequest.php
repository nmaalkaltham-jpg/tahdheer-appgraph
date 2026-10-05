<?php

namespace App\Domain\Search;

/**
 * Immutable provider input built by the QueryPipeline. `query` is the
 * normalized residual lexical query (entity tokens already consumed);
 * `expandedTokens` are active-synonym additions (never shown to the user).
 */
final class SearchRequest
{
    /**
     * @param  array{stage: ?string, grade: ?string, subject: ?string, semester: ?string, kind: ?string, format: ?string}  $filters  slugs or null
     * @param  array<int, string>  $expandedTokens
     * @param  'all'|'pptx'|'pdf'|'docx'  $typeTab
     */
    public function __construct(
        public readonly string $query,
        public readonly string $rawQuery,
        public readonly array $filters = [
            'stage' => null, 'grade' => null, 'subject' => null,
            'semester' => null, 'kind' => null, 'format' => null,
        ],
        public readonly ?bool $editable = null,
        public readonly array $expandedTokens = [],
        public readonly string $typeTab = 'all',
        public readonly string $sort = 'relevance',
        public readonly int $page = 1,
        public readonly int $perPage = 24,
        /*
         * أبعادٌ «ليّنة»: المنتج يمرّ إن طابق القيمة **أو** لم يُصنَّف في
         * هذا البعد أصلًا. يستعملها الرجوعُ النصّيّ وحده — مرشِّحٌ اشتُقّ من
         * كلمةٍ لا يُسقط منتجًا لم يُصنَّف، ولا يُدخل منتجًا يناقضه.
         *
         * @var list<string>
         */
        public readonly array $lenient = [],
    ) {}

    /**
     * قيمُ البُعد قائمةً — مفردةٌ أو اختيارٌ متعدّد («أو» داخل البُعد).
     *
     * @return list<string>
     */
    public function values(string $dimension): array
    {
        $value = $this->filters[$dimension] ?? null;
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return array_values(array_unique(array_map('strval', (array) $value)));
    }

    public function isLenient(string $dimension): bool
    {
        return in_array($dimension, $this->lenient, true);
    }

    /** @return array<int, string> residual + synonym tokens, unique, ordered */
    public function allTokens(): array
    {
        $tokens = $this->query === '' ? [] : (preg_split('/\s+/u', $this->query) ?: []);

        return array_values(array_unique([...$tokens, ...$this->expandedTokens]));
    }
}

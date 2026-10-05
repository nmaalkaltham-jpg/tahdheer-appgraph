<?php

namespace App\Domain\SEO;

/**
 * The arrays a page actually renders.
 *
 * This DTO is the mechanism behind the «visible = schema» law (MIP §7.4): the
 * SAME instance is serialised into the API payload the storefront renders AND
 * passed to SchemaBuilder. There is no path by which markup can describe
 * something the page does not show, because there is only one array.
 */
final class VisibleContent
{
    /**
     * @param  list<array{name: string, url: string}>  $breadcrumbs  rendered by components/seo/Breadcrumbs.tsx
     * @param  list<array{question: string, answer: string}>  $faqs  rendered in the visible FAQ block
     * @param  array<string, scalar|null>  $facts  rendered in the visible facts table
     * @param  array{name: string, url: ?string, bio: ?string}|null  $author  rendered in the author block
     */
    public function __construct(
        public readonly array $breadcrumbs = [],
        public readonly array $faqs = [],
        public readonly array $facts = [],
        public readonly ?array $author = null,
        public readonly ?string $description = null,
        public readonly ?string $image = null,
    ) {}

    /** @return array<string, mixed> camelCase payload for the storefront. */
    public function toArray(): array
    {
        return [
            'breadcrumbs' => $this->breadcrumbs,
            'faqs' => $this->faqs,
            'facts' => $this->facts,
            'author' => $this->author,
            'description' => $this->description,
            'image' => $this->image,
        ];
    }
}

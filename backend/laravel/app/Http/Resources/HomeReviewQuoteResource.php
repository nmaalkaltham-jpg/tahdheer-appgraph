<?php

namespace App\Http\Resources;

use App\Domain\Reviews\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One homepage testimonial.
 *
 * Field names are snake_case here — unlike every other resource in this app —
 * because the shipped storefront declares the homepage DTOs that way
 * (apps/storefront/components/home/sections.tsx). The contract is the client's;
 * renaming it server-side would only produce an endpoint nothing consumes.
 *
 * `verified` is recomputed from the row's own order_id rather than assumed
 * from the query that selected it, and `author` reuses the product-page
 * masking (ReviewResource::displayName) so the same buyer is shown the same
 * way in both places. `author_role` and `author_city` are the reviewer's own
 * words (reviews.author_role / author_city, migration 2026_09_17_000002) and
 * are emitted only when filled — never a guessed «معلمة رياضيات».
 *
 * @mixin Review
 */
class HomeReviewQuoteResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $out = [
            'id' => $this->id,
            'text' => (string) $this->body,
            'author' => ReviewResource::displayName($this->customer?->name),
            'product_slug' => $this->product->slug,
            'product_title' => $this->product->title,
            'verified' => $this->order_id !== null,
        ];

        /*
         * الصفة والمدينة كما كتبهما صاحب المراجعة — وهما ما تعرضه صفحة
         * المنتج لصاحب المراجعة نفسه. كان الشريط الرئيس يُسقطهما فيبدو
         * المراجع بلا صفةٍ هنا وبصفته هناك، والـDTO في الواجهة يقرؤهما أصلًا.
         */
        if (filled($this->author_role)) {
            $out['author_role'] = $this->author_role;
        }
        if (filled($this->author_city)) {
            $out['author_city'] = $this->author_city;
        }

        /*
         * النسخة الموحَّدة الاسم مع `ReviewResource` (authorRole/authorCity) —
         * حاضرةٌ دائمًا وnull حين لم يكتبهما المراجع، كي تفرّق البطاقة بين
         * «لا صفة» و«لم يصل الحقل». والمفتاحان القديمان يبقيان لمستهلكيهما.
         */
        $out['authorRole'] = filled($this->author_role) ? $this->author_role : null;
        $out['authorCity'] = filled($this->author_city) ? $this->author_city : null;

        if ($this->created_at !== null) {
            $out['created_at'] = $this->created_at->toIso8601String();
        }

        return $out;
    }
}

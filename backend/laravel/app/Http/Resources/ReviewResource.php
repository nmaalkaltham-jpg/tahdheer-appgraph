<?php

namespace App\Http\Resources;

use App\Domain\Privacy\PrivacyService;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewCategoryScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One public review. Keys are camelCase (the single API convention); storage
 * stays snake_case.
 *
 * Honesty rules encoded here:
 *  · `verifiedPurchase` is true ONLY when a real order backs the row. It is
 *    never inferred from the review existing.
 *  · Optional blocks (reply, category scores, media, variant) are OMITTED when
 *    absent — never emitted empty or zeroed, so the UI has nothing to fake.
 *  · `authorName` is derived from the account. There is no free-text name in
 *    the schema, so a display name cannot be spoofed.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $out = [
            'id' => $this->id,
            'rating' => $this->rating,
            'authorName' => self::displayName(
                $this->resource->relationLoaded('customer') ? $this->customer?->name : null,
            ),
            'verifiedPurchase' => $this->order_id !== null,
            'helpfulCount' => $this->helpful_count,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];

        /*
         * صفة المراجِع ومدينته — كلٌّ منهما مستقلّ ويُسقَط حين يغيب. التركيب
         * («معلّمة علوم، الرياض») شأن الواجهة: العطف بفاصلةٍ عربيّة قرارُ
         * تصييرٍ لا حقيقةٌ مخزَّنة، وتخزينه مركَّبًا يمنع عرض أحدهما وحده.
         */
        if (filled($this->author_role)) {
            $out['authorRole'] = $this->author_role;
        }

        if (filled($this->author_city)) {
            $out['authorCity'] = $this->author_city;
        }

        if (filled($this->title)) {
            $out['title'] = $this->title;
        }

        if (filled($this->body)) {
            $out['body'] = $this->body;
        }

        // The exact edition the reviewer bought — shown so a 2-star review of
        // an old version is not read as a verdict on the current one.
        if (filled($this->variant_purchased)) {
            $out['variantPurchased'] = $this->variant_purchased;
        }

        if ($this->resource->relationLoaded('categoryScores') && $this->categoryScores->isNotEmpty()) {
            $out['categoryScores'] = $this->categoryScores
                ->filter(fn ($s) => array_key_exists($s->criterion, ReviewCategoryScore::CRITERIA))
                ->map(fn ($s) => [
                    'criterion' => $s->criterion,
                    'label' => ReviewCategoryScore::CRITERIA[$s->criterion],
                    'score' => $s->score,
                ])->values();
        }

        if ($this->resource->relationLoaded('reply') && $this->reply !== null) {
            $out['reply'] = [
                'body' => $this->reply->body,
                'author' => $this->reply->author_label,
                'publishedAt' => $this->reply->published_at?->toIso8601String(),
            ];
        }

        $media = $this->publishableMedia();
        if ($media !== []) {
            $out['media'] = $media;
        }

        return $out;
    }

    /**
     * Media is published ONLY when we actually hold a URL for it. Assets stored
     * in object storage have no public URL until the signed-URL adapter lands,
     * so they are omitted rather than linked to a guessed path.
     *
     * TODO: replace with StorageProviderInterface::url() once the storage
     * adapter exists; the moderation gate below stays either way.
     *
     * @return list<array{url: string, alt: string}>
     */
    private function publishableMedia(): array
    {
        if (! $this->resource->relationLoaded('media')) {
            return [];
        }

        $out = [];
        foreach ($this->media as $item) {
            $ref = (string) ($item->provider_ref ?? '');
            // Paid-content sanctity: a private asset never reaches a renderer.
            if ($item->visibility !== 'public_sample' || ! str_starts_with($ref, 'https://')) {
                continue;
            }
            $out[] = ['url' => $ref, 'alt' => (string) ($item->alt ?? '')];
        }

        return $out;
    }

    /**
     * «نورة العتيبي» → «نورة ع.» — the account's own name, with the family name
     * reduced to an initial. Nothing is invented; a single-token name is shown
     * as-is, and a missing account yields an explicit «مشترٍ موثّق».
     *
     * The «ال» definite article is stripped before taking the initial:
     * «العتيبي» and «القحطاني» both begin with alif, so keeping it would render
     * every Saudi family name as the same «ا.» and identify no one.
     */
    public static function displayName(?string $accountName): string
    {
        $name = trim((string) $accountName);

        if ($name === '') {
            return 'مشترٍ موثّق';
        }

        /*
         * الاسم المطموس لا يُقصّ إلى اسمٍ يشبه اسمًا.
         *
         * بعد محو بيانات صاحبها يصير الاسم `[محذوف بطلب صاحب البيانات]`،
         * والاختصار أدناه يقطعه إلى «[محذوف ب.» فيُعرض كأنّه شخصٌ حقيقيّ،
         * وحرفه الأوّل «[» في دائرة الأحرف. أي أنّ الصفحة تُظهر إنسانًا حيث
         * تقول البيانات إنّه مُحي. والجواب الصادق هو جواب الاسم الغائب نفسه.
         */
        if ($name === PrivacyService::REDACTED) {
            return 'مشترٍ موثّق';
        }

        $parts = preg_split('/\s+/u', $name) ?: [];

        if (count($parts) < 2) {
            return $name;
        }

        $family = (string) end($parts);
        if (mb_strlen($family) > 2 && mb_substr($family, 0, 2) === 'ال') {
            $family = mb_substr($family, 2);
        }

        return $parts[0].' '.mb_substr($family, 0, 1).'.';
    }
}

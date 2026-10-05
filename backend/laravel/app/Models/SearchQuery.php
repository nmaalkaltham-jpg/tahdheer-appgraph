<?php

namespace App\Models;

use App\Domain\Privacy\CredentialPaths;
use App\Domain\Search\ArabicNormalizer;
use Illuminate\Database\Eloquent\Model;

class SearchQuery extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * Admins read search queries. A query that holds an order link (a buyer pasting it into
     * search) is stored redacted, whichever writer saves it; the normalized form is re-derived
     * because normalisation can strip the slashes the redactor keys on.
     */
    protected static function booted(): void
    {
        static::saving(function (self $query): void {
            if (CredentialPaths::tokensIn((string) $query->raw_query) === []) {
                return;
            }

            $query->raw_query = CredentialPaths::redactText((string) $query->raw_query);
            $query->normalized_query = mb_substr(ArabicNormalizer::normalize($query->raw_query), 0, 500);
        });
    }

    protected function casts(): array
    {
        return [
            'zero_result' => 'boolean',
            'degraded' => 'boolean',
            'created_at' => 'datetime',
            'filters' => 'array',
            'result_ids' => 'array',
        ];
    }
}

<?php

namespace App\Domain\Redirects;

use Illuminate\Database\Eloquent\Model;

/**
 * قاعدة تحويل واحدة. `from_path` هو المصدر ويدعم `*` في نهايته كالتقاط.
 *
 * Column mapping to the MIP §7.7 vocabulary:
 *   source → from_path · target → to_path · 301|302 → status_code
 *   plus enabled, hits, reason.
 */
class RedirectRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'status_code' => 'integer',
            'hits' => 'integer',
            'version' => 'integer',
        ];
    }

    /** True when the source ends in the trailing wildcard capture. */
    public function isWildcard(): bool
    {
        return str_ends_with((string) $this->from_path, '*');
    }
}

<?php

namespace App\Domain\SEO\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A real, consented author. `consented = false` (the default) keeps the person
 * out of ProfilePage/Person markup entirely — consent is opt-in, never assumed
 * (MIP §7.4 «consented author bios»).
 */
class ProductAuthor extends Model
{
    protected $table = 'product_authors';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['consented' => 'boolean'];
    }
}

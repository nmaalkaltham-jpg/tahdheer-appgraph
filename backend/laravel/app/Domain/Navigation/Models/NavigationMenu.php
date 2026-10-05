<?php

declare(strict_types=1);

namespace App\Domain\Navigation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * قائمة تنقّل في مكان واحد من المتجر.
 *
 * @property string $location
 * @property array<int, array<string, mixed>> $draft_nodes
 * @property array<int, array<string, mixed>>|null $published_nodes
 */
class NavigationMenu extends Model
{
    protected $table = 'navigation_menus';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'draft_nodes' => 'array',
            'published_nodes' => 'array',
            'version' => 'integer',
            'published_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}

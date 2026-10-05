<?php

namespace App\Domain\UrlPlatform\Models;

use App\Domain\UrlPlatform\UrlStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** صفّ سجلّ العناوين — يُكتب من `UrlRegistry` وحده. */
class UrlRegistryEntry extends Model
{
    protected $table = 'url_registry';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'indexable' => 'boolean',
            'reserved' => 'boolean',
            'version' => 'integer',
            'redirect_rule_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function status(): UrlStatus
    {
        return UrlStatus::from((string) $this->status);
    }

    public function history(): HasMany
    {
        return $this->hasMany(UrlHistory::class, 'registry_id')->orderByDesc('id');
    }

    /** @return array<string, mixed> الحقول القياسيّة (§1) */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'entityType' => $this->entity_type,
            'entityId' => $this->entity_id,
            'store' => $this->store,
            'locale' => $this->locale,
            'slug' => $this->slug,
            'path' => $this->path,
            'publicUrl' => $this->canonical_url ?? $this->path,
            'canonicalUrl' => $this->canonical_url,
            'previewUrl' => null,
            'routeOwner' => $this->route_owner,
            'status' => $this->status,
            'indexable' => $this->indexable,
            'reserved' => $this->reserved,
            'redirectRuleId' => $this->redirect_rule_id,
            'version' => $this->version,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

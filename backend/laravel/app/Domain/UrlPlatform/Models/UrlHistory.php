<?php

namespace App\Domain\UrlPlatform\Models;

use Illuminate\Database\Eloquent\Model;

/** صفّ تاريخ العنوان — يُلحَق ولا يُعدَّل ولا يُحذف. */
class UrlHistory extends Model
{
    protected $table = 'url_history';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'registry_id' => 'integer',
            'entity_id' => 'integer',
            'actor_id' => 'integer',
            'redirect_rule_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'registryId' => $this->registry_id,
            'entityType' => $this->entity_type,
            'entityId' => $this->entity_id,
            'locale' => $this->locale,
            'event' => $this->event,
            'previousSlug' => $this->previous_slug,
            'previousPath' => $this->previous_path,
            'newSlug' => $this->new_slug,
            'newPath' => $this->new_path,
            'actorId' => $this->actor_id,
            'reason' => $this->reason,
            'changeSource' => $this->change_source,
            'redirectRuleId' => $this->redirect_rule_id,
            'approvalState' => $this->approval_state,
            'correlationId' => $this->correlation_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

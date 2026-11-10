<?php

namespace App\Services\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\User;
use Illuminate\Support\Collection;

final class AuditActorPresenter
{
    public const SYSTEM_EVIDENCE = "(source = 'scheduler' OR properties::jsonb->'automatic_process' = 'true'::jsonb OR properties->'actor_snapshot'->>'kind' = 'system')";

    /** @param Collection<int, AuditEntry> $entries */
    public function prepare(Collection $entries): void
    {
        $ids = $entries->filter(fn (AuditEntry $entry): bool => $this->isPerson($entry))->pluck('causer_id')->filter()->unique();
        $users = User::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'deleted_at'])->keyBy('id');
        foreach ($entries as $entry) {
            $entry->setRelation('auditActor', $this->isPerson($entry) ? $users->get($entry->causer_id) : null);
        }
    }

    public function isPerson(AuditEntry $entry): bool
    {
        return in_array($entry->causer_type, [User::class, (new User)->getMorphClass()], true) && $entry->causer_id !== null;
    }

    public function kind(AuditEntry $entry): string
    {
        if ($this->isPerson($entry)) {
            return 'person';
        }

        return $entry->causer_id === null && (($entry->properties?->get('actor_snapshot')['kind'] ?? null) === 'system'
            || $entry->source === 'scheduler' || $entry->properties?->get('automatic_process') === true)
            ? 'system' : 'unavailable';
    }

    /** @return array<string, mixed> */
    public function present(AuditEntry $entry, User $viewer): array
    {
        $kind = $this->kind($entry);
        $user = $entry->relationLoaded('auditActor') ? $entry->getRelation('auditActor') : null;
        $name = $entry->properties?->get('actor_snapshot')['name'] ?? null;
        $name = $kind === 'system' ? 'Sistema' : ($kind === 'person' && is_string($name) ? $name : null);

        return [
            'type' => $entry->causer_type, 'id' => $entry->causer_id, 'kind' => $kind,
            'name' => $name, 'current_name' => $user instanceof User ? $user->name : null,
            'exists' => $user instanceof User,
            'status' => $kind === 'system' ? 'system' : ($user instanceof User ? ($user->trashed() ? 'disabled' : 'active') : 'unavailable'),
            'can_manage' => $user instanceof User && $viewer->can('can_manage_users'),
        ];
    }
}

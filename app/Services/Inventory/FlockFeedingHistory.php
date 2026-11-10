<?php

namespace App\Services\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedConsumptionCorrection;
use App\Models\Inventory\FeedRecipe;
use App\Models\Lots\Flock;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class FlockFeedingHistory
{
    public function __construct(private AuditRecorder $recorder) {}

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function record(Model $subject, Flock $flock, ?User $actor, string $event, string $operationId, array $before, array $after, string $source = 'api'): void
    {
        $recipeId = $after['recipe_id'] ?? $after['feed_recipe_id'] ?? $after['stage_snapshot']['recipe_id'] ?? null;
        $recipeName = $recipeId === null ? null : FeedRecipe::query()->whereKey($recipeId)->value('name');
        $consumptionId = $subject instanceof FeedConsumption ? $subject->id : ($subject instanceof FeedConsumptionCorrection ? $subject->feed_consumption_id : null);
        $previous = $consumptionId === null || $before === [] ? null : AuditEntry::query()
            ->where('properties->consumption_id', $consumptionId)->whereIn('event', ['feed_consumption_recorded', 'feed_consumption_corrected'])
            ->orderByDesc('id')->first();
        $houseName = PoultryHouse::query()->whereKey($flock->poultry_house_id)->value('name');
        $presentationSnapshot = [
            'subject' => ['resource' => 'flock', 'id' => $flock->public_id, 'label' => $flock->code],
            'recipe_name' => $recipeName, 'before_recipe_name' => $previous?->properties?->get('presentation_snapshot')['recipe_name'] ?? null,
            'poultry_house_name' => $houseName,
        ];
        $this->recorder->record(AuditEntryData::forSubject(
            subject: $subject, actor: $actor, logName: 'inventory', event: $event,
            description: match ($event) {
                'flock_feeding_initialized' => 'Alimentación del lote inicializada',
                'flock_feeding_manual_changed' => 'Configuración manual de alimentación modificada',
                'flock_feeding_manual_confirmed' => 'Ración manual del lote confirmada',
                'flock_feeding_automatic_restored' => 'Alimentación automática restablecida',
                'flock_feeding_plan_revised' => 'Etapas de alimentación del lote revisadas',
                'feed_consumption_corrected' => 'Consumo diario rectificado con compensación',
                default => 'Consumo diario de alimento registrado automáticamente',
            },
            operationId: $operationId, source: $source, upId: $flock->production_unit_id,
            properties: ['result' => 'success', 'flock_id' => $flock->public_id, 'subject_snapshot' => $after, 'automatic_process' => $actor === null, 'consumption_id' => $consumptionId, 'presentation_snapshot' => $presentationSnapshot],
            attributeChanges: ['old' => $before, 'new' => $after],
        ));
    }
}

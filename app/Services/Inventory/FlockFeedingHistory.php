<?php

namespace App\Services\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\Flock;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class FlockFeedingHistory
{
    public function __construct(private AuditRecorder $recorder) {}

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function record(Model $subject, Flock $flock, ?User $actor, string $event, string $operationId, array $before, array $after, string $source = 'api'): void
    {
        $this->recorder->record(AuditEntryData::forSubject(
            subject: $subject, actor: $actor, logName: 'inventory', event: $event,
            description: match ($event) {
                'flock_feeding_initialized' => 'Alimentación del lote inicializada',
                'flock_feeding_manual_changed' => 'Configuración manual de alimentación modificada',
                'flock_feeding_automatic_restored' => 'Alimentación automática restablecida',
                'flock_feeding_plan_revised' => 'Etapas de alimentación del lote revisadas',
                'feed_consumption_corrected' => 'Consumo diario rectificado con compensación',
                default => 'Consumo diario de alimento registrado automáticamente',
            },
            operationId: $operationId, source: $source, upId: $flock->production_unit_id,
            properties: ['result' => 'success', 'flock_id' => $flock->public_id, 'subject_snapshot' => $after, 'automatic_process' => $actor === null],
            attributeChanges: ['old' => $before, 'new' => $after],
        ));
    }
}

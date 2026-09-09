<?php

namespace App\Actions\Lots;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Events\Lots\WeighingReferenceSettingsSaved;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use App\Services\Lots\RunLotsCommand;
use App\Services\Lots\WeighingMath;
use App\Services\Lots\WeighingPresenter;
use Illuminate\Support\Facades\DB;

final readonly class SaveWeighingReferenceSettingsAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private AuditRecorder $auditRecorder,
        private WeighingMath $math,
        private WeighingPresenter $presenter,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute(
            $actor,
            'weighing-settings.manage',
            'weighing-settings.save',
            $data['idempotency_key'],
            $data,
            function (string $operationId) use ($data, $actor, $source): array {
                $this->lockSettingsSingleton();
                $settings = WeighingReferenceSettings::query()->lockForUpdate()->first();
                $before = $settings === null ? [] : $this->presenter->settings($settings);
                if ($settings !== null && (int) ($data['version'] ?? 0) !== $settings->version) {
                    throw new LotsConflict(
                        'La configuración de pesajes cambió de versión. Actualiza los datos antes de reintentar.',
                        code: 'WEIGHING_REFERENCE_VERSION_CONFLICT',
                        meta: ['current_version' => $settings->version],
                    );
                }

                $attributes = [
                    'singleton_key' => true,
                    'adult_from_week' => (int) $data['adult_from_week'],
                    'chick_min_weight_g' => $this->math->toGrams((string) $data['chick_min_weight'], (string) $data['unit']),
                    'chick_max_weight_g' => $this->math->toGrams((string) $data['chick_max_weight'], (string) $data['unit']),
                    'adult_min_weight_g' => $this->math->toGrams((string) $data['adult_min_weight'], (string) $data['unit']),
                    'adult_max_weight_g' => $this->math->toGrams((string) $data['adult_max_weight'], (string) $data['unit']),
                    'captured_unit' => $data['unit'],
                    'version' => $settings === null ? 1 : $settings->version + 1,
                ];
                if ($this->math->compare($attributes['chick_min_weight_g'], $attributes['chick_max_weight_g']) >= 0 || $this->math->compare($attributes['adult_min_weight_g'], $attributes['adult_max_weight_g']) >= 0) {
                    throw new LotsConflict('Cada mínimo debe ser positivo y menor que su máximo.', code: 'WEIGHING_REFERENCE_INVALID_RANGE');
                }

                $settings ??= new WeighingReferenceSettings;
                $settings->forceFill($attributes)->save();
                $after = $this->presenter->settings($settings);
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $settings,
                    actor: $actor,
                    logName: 'lots',
                    event: 'weighing_reference_saved',
                    description: 'Configuración global de pesajes guardada',
                    properties: ['result' => 'success', 'subject_snapshot' => $after],
                    attributeChanges: ['old' => $before, 'new' => $after],
                    operationId: $operationId,
                    source: $source,
                ));
                event(new WeighingReferenceSettingsSaved($operationId, $actor->id));

                return ['settings' => $after];
            },
        );
    }

    private function lockSettingsSingleton(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SELECT pg_advisory_xact_lock(hashtext('weighing_reference_settings'))");
    }
}

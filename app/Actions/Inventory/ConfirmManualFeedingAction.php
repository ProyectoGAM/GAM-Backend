<?php

namespace App\Actions\Inventory;

use App\Actions\Notifications\ResolveAlertNotificationAction;
use App\Enums\Notifications\AlertTimingType;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Notifications\AppNotification;
use App\Models\User;
use App\Services\Inventory\FlockFeedingHistory;
use App\Services\Inventory\FlockFeedingResolver;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\RunLotsCommand;
use Illuminate\Support\Facades\DB;

final readonly class ConfirmManualFeedingAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private FlockFeedingResolver $feeding,
        private FlockFeedingHistory $history,
        private FlockActivityJournal $journal,
        private ResolveAlertNotificationAction $resolveAlert,
        private Clock $clock,
    ) {}

    public function execute(Flock $flock, int $configurationId, string $key, User $actor): FlockOperation
    {
        return $this->commands->execute($actor, 'can_manage_flock_feeding', 'flock.feeding.confirm', $key,
            ['flock' => $flock->public_id, 'configuration_id' => $configurationId],
            function (string $operationId) use ($flock, $configurationId, $actor): array {
                $locked = Flock::query()->whereKey($flock->id)->lockForUpdate()->firstOrFail();
                $this->state->open($locked);
                $alertType = AlertTimingType::ManualFeedingReviewDue->value;
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$alertType.'-alerts:'.$locked->production_unit_id]);
                $now = $this->clock->now()->setTimezone(FlockFeedingResolver::TIMEZONE);
                $configuration = $this->feeding->configuration($locked, $now);
                if ($configuration->mode !== 'manual' || $configuration->id !== $configurationId) {
                    throw new LotsConflict('La alimentación vigente cambió. Volvé a consultar la ración manual antes de confirmarla.');
                }

                $before = ['confirmada_en' => $configuration->manual_confirmed_at?->toIso8601String(), 'confirmada_por' => $configuration->manual_confirmed_by];
                $configuration->forceFill([
                    'manual_review_since' => $now->toDateString(),
                    'manual_confirmed_at' => $now,
                    'manual_confirmed_by' => $actor->id,
                ])->save();
                $snapshot = [
                    'configuracion_id' => $configuration->id,
                    'feed_recipe_id' => $configuration->feed_recipe_id,
                    'cantidad_diaria_g' => (string) $configuration->daily_quantity_grams,
                    'confirmada_en' => $now->toIso8601String(),
                    'confirmada_por' => $actor->id,
                    'revision_manual_desde' => $now->toDateString(),
                ];
                $this->history->record($configuration, $locked, $actor, 'flock_feeding_manual_confirmed', $operationId, $before, $snapshot);
                $this->journal->record($locked, $operationId, 'feeding_manual_confirmed', 'flock.feeding.confirm');

                $alerts = AppNotification::query()->where('module', 'management_plans')->where('type', $alertType)
                    ->where('production_unit_id', $locked->production_unit_id)->where('entity_type', 'flock')
                    ->where('entity_id', $locked->public_id)->where('is_alert', true)->whereNull('resolved_at');
                foreach ($alerts->lazyById(100) as $alert) {
                    $this->resolveAlert->execute($alert);
                }

                return ['confirmacion' => $snapshot];
            });
    }
}

<?php

namespace App\Actions\ManagementPlans;

use App\Actions\Notifications\PublishNotificationAction;
use App\Actions\Notifications\ResolveAlertNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\ManagementPlans\HealthActivityType;
use App\Enums\Notifications\NotificationUrgency;
use App\Events\Notifications\NotificationInboxChanged;
use App\Interfaces\Clock;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Notifications\AppNotification;
use App\Services\ManagementPlans\ScheduledHealthActivities;
use App\Services\Notifications\AlertRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EvaluateHealthAlertsAction
{
    private const string MODULE = 'management_plans';

    public function __construct(
        private ScheduledHealthActivities $activities,
        private AlertRecipients $recipients,
        private PublishNotificationAction $publish,
        private ResolveAlertNotificationAction $resolve,
        private Clock $clock,
    ) {}

    public function execute(int $unitId, HealthActivityType $type): void
    {
        DB::transaction(function () use ($unitId, $type): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$type->value.'-alerts:'.$unitId]);
            $unit = ProductionUnit::query()->findOrFail($unitId);
            $evaluatedAt = $this->clock->now()->setTimezone((string) config('lots.timezone'));
            $today = $evaluatedAt->startOfDay();
            $pending = $unit->status === ProductionUnitStatus::Active
                ? iterator_to_array($this->activities->pending($type, $unitId, $evaluatedAt, $today->addDays(7)))
                : [];
            $recipients = $pending === [] ? [] : $this->recipients->ids($unitId, 'can_view_management_plans');
            $open = [];

            $notificationType = $type->value.'_due';
            $notices = AppNotification::query()->where('module', self::MODULE)->where('type', $notificationType)
                ->where('production_unit_id', $unitId)->where('is_alert', true)->whereNull('resolved_at');

            foreach ($notices->lazyById(100) as $notice) {
                $key = Str::beforeLast($notice->source_key, ':');
                $dose = $pending[$key] ?? null;
                if ($dose === null || ! isset($recipients[$notice->recipient_id])) {
                    $this->resolve->execute($notice);

                    continue;
                }

                $open[$key][$notice->recipient_id] = true;
                $urgency = $dose['due']->lessThanOrEqualTo($today) ? NotificationUrgency::High : NotificationUrgency::Medium;
                if ($notice->urgency === NotificationUrgency::Medium && $urgency === NotificationUrgency::High) {
                    $notice->read_at = null;
                }
                $notice->fill([
                    'entity_id' => $dose['entity_id'],
                    'urgency' => $urgency,
                    'message' => $this->message($type, $dose['activity'], $dose['flock'], $dose['due']->format('d/m/Y')),
                ]);
                if ($notice->isDirty()) {
                    $notice->save();
                    NotificationInboxChanged::dispatch($notice->recipient_id, $unitId);
                }
            }

            foreach ($pending as $key => $dose) {
                $urgency = $dose['due']->lessThanOrEqualTo($today) ? NotificationUrgency::High : NotificationUrgency::Medium;
                foreach ($recipients as $recipientId => $_) {
                    if (isset($open[$key][$recipientId])) {
                        continue;
                    }

                    $this->publish->execute(new NotificationData(
                        recipientId: $recipientId,
                        type: $notificationType,
                        sourceKey: $key.':'.Str::uuid(),
                        title: $type === HealthActivityType::Vaccination ? 'Vacunación pendiente' : 'Medicación pendiente',
                        message: $this->message($type, $dose['activity'], $dose['flock'], $dose['due']->format('d/m/Y')),
                        occurredAt: $evaluatedAt,
                        productionUnitId: $unitId,
                        entityType: 'flock_plan_activity',
                        entityId: $dose['entity_id'],
                        urgency: $urgency,
                        module: self::MODULE,
                        isAlert: true,
                    ));
                }
            }
        }, 3);
    }

    private function message(HealthActivityType $type, string $activity, string $flock, string $due): string
    {
        $label = $type === HealthActivityType::Vaccination ? 'vacuna' : 'medicación';

        return "La {$label} «{$activity}» del lote «{$flock}» sigue pendiente. Fecha prevista: {$due}.";
    }
}

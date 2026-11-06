<?php

namespace App\Actions\Inventory;

use App\Actions\Notifications\PublishNotificationAction;
use App\Actions\Notifications\ResolveAlertNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\Lots\FlockStatus;
use App\Enums\Notifications\NotificationUrgency;
use App\Events\Notifications\NotificationInboxChanged;
use App\Interfaces\Clock;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Notifications\AppNotification;
use App\Services\Inventory\FlockFeedingResolver;
use App\Services\Notifications\AlertRecipients;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EvaluateFeedCoverageAlertsAction
{
    private const string TYPE = 'feed_coverage';

    private const string MODULE = 'management_plans';

    public function __construct(
        private FlockFeedingResolver $feeding,
        private PublishNotificationAction $publish,
        private ResolveAlertNotificationAction $resolve,
        private AlertRecipients $recipients,
        private Clock $clock,
    ) {}

    public function execute(int $unitId): void
    {
        DB::transaction(function () use ($unitId): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['feed-coverage:'.$unitId]);
            $unit = ProductionUnit::query()->findOrFail($unitId);
            $evaluatedAt = $this->clock->now()->setTimezone(FlockFeedingResolver::TIMEZONE);
            $shortages = $unit->status === ProductionUnitStatus::Active ? $this->shortages($unit, $evaluatedAt) : [];
            $recipients = $shortages === [] ? [] : $this->recipients->ids($unitId, 'can_view_feed_stock');
            $open = [];

            $notices = AppNotification::query()->where('module', self::MODULE)->where('type', self::TYPE)
                ->where('production_unit_id', $unitId)->where('is_alert', true)->whereNull('resolved_at');

            foreach ($notices->lazyById(100) as $notice) {
                $recipeId = (int) $notice->entity_id;
                $shortage = $shortages[$recipeId] ?? null;
                if ($shortage === null) {
                    $this->resolve->execute($notice);

                    continue;
                }

                $open[$recipeId][$notice->recipient_id] = true;
                if (isset($recipients[$notice->recipient_id])) {
                    $this->updateNotice($notice, $shortage);
                }
            }

            foreach ($shortages as $recipeId => $shortage) {
                foreach ($recipients as $recipientId => $_) {
                    if (isset($open[$recipeId][$recipientId])) {
                        continue;
                    }

                    $this->publish->execute(new NotificationData(
                        recipientId: $recipientId,
                        type: self::TYPE,
                        sourceKey: 'feed-coverage:'.$unitId.':'.$recipeId.':'.Str::uuid(),
                        title: $shortage['title'],
                        message: $shortage['message'],
                        occurredAt: $evaluatedAt,
                        productionUnitId: $unitId,
                        entityType: 'feed_recipe',
                        entityId: (string) $recipeId,
                        urgency: $shortage['urgency'],
                        module: self::MODULE,
                        isAlert: true,
                    ));
                }
            }
        }, 3);
    }

    /** @return array<int, array{urgency: NotificationUrgency, title: string, message: string}> */
    private function shortages(ProductionUnit $unit, CarbonImmutable $evaluatedAt): array
    {
        $demand = $this->dailyDemand($unit->id, $evaluatedAt);
        $balances = FeedBalance::query()->where('production_unit_id', $unit->id)
            ->whereIn('feed_recipe_id', array_keys($demand))->pluck('quantity_grams', 'feed_recipe_id');
        $recipes = FeedRecipe::query()->whereKey(array_keys($demand))->pluck('name', 'id');
        $shortages = [];

        foreach ($demand as $recipeId => $dailyGrams) {
            $stock = BigInteger::of(max(0, (int) ($balances[$recipeId] ?? 0)));
            if ($stock->isGreaterThanOrEqualTo($dailyGrams->multipliedBy($unit->feed_coverage_warning_days))) {
                continue;
            }

            $urgent = $stock->isLessThanOrEqualTo($dailyGrams->multipliedBy($unit->feed_coverage_urgent_days));
            $shortages[$recipeId] = [
                'urgency' => $urgent ? NotificationUrgency::High : NotificationUrgency::Medium,
                'title' => $urgent ? 'Cobertura urgente de ración' : 'Cobertura baja de ración',
                'message' => $urgent
                    ? "La ración «{$recipes[$recipeId]}» en «{$unit->name}» alcanza para {$unit->feed_coverage_urgent_days} días o menos."
                    : "La ración «{$recipes[$recipeId]}» en «{$unit->name}» alcanza para menos de {$unit->feed_coverage_warning_days} días.",
            ];
        }

        return $shortages;
    }

    /** @return array<int, BigInteger> */
    private function dailyDemand(int $unitId, CarbonImmutable $evaluatedAt): array
    {
        $date = $evaluatedAt->startOfDay();
        $configurations = FlockFeedingChange::query()->join('flocks', 'flocks.id', '=', 'flock_feeding_changes.flock_id')
            ->where('flocks.production_unit_id', $unitId)
            ->where('flocks.status', '!=', FlockStatus::Finished)
            ->where('flocks.established_at', '<=', $evaluatedAt->toIso8601String())
            ->where('effective_date', '<=', $date->toDateString())
            ->selectRaw('DISTINCT ON (flock_id) flock_feeding_changes.*, flocks.current_quantity as forecast_population')
            ->orderBy('flock_id')->orderByDesc('effective_date')->orderByDesc('flock_feeding_changes.id')->get();

        $demand = [];
        foreach ($configurations as $configuration) {
            if ($configuration->mode === 'manual') {
                $recipeId = $configuration->feed_recipe_id;
                $grams = BigInteger::of($configuration->daily_quantity_grams ?? 0);
            } else {
                $stage = $this->feeding->stage($configuration, $date);
                $recipeId = (int) $stage['recipe_id'];
                $grams = BigInteger::of($stage['grams_per_bird'])->multipliedBy(max(0, (int) $configuration->getAttribute('forecast_population')));
            }

            if ($grams->isPositive()) {
                $demand[$recipeId] = ($demand[$recipeId] ?? BigInteger::zero())->plus($grams);
            }
        }

        return $demand;
    }

    /** @param array{urgency: NotificationUrgency, title: string, message: string} $shortage */
    private function updateNotice(AppNotification $notice, array $shortage): void
    {
        if ($notice->urgency === NotificationUrgency::Medium && $shortage['urgency'] === NotificationUrgency::High) {
            $notice->read_at = null;
        }
        $notice->fill($shortage);

        if ($notice->isDirty()) {
            $notice->save();
            NotificationInboxChanged::dispatch($notice->recipient_id, $notice->production_unit_id);
        }
    }
}

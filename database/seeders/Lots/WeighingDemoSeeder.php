<?php

namespace Database\Seeders\Lots;

use App\Actions\Lots\RecordWeighingAction;
use App\Actions\Lots\SaveWeighingReferenceSettingsAction;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use Illuminate\Database\Seeder;

final class WeighingDemoSeeder extends Seeder
{
    public function run(SaveWeighingReferenceSettingsAction $settings, RecordWeighingAction $weighings): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        if (WeighingReferenceSettings::query()->exists()) {
            return;
        }
        $this->onceOperation($actor, '00000000-0000-4000-8800-000000000001', fn (string $key): FlockOperation => $settings->execute([
            'adult_from_week' => 18,
            'unit' => 'g',
            'chick_min_weight' => '10.0',
            'chick_max_weight' => '100.0',
            'adult_min_weight' => '100.0',
            'adult_max_weight' => '3000.0',
            'idempotency_key' => $key,
        ], $actor, 'seeder'));

        $individualFlock = Flock::query()->where('code', 'DEMO-LOT-A')->firstOrFail();
        $this->onceOperation($actor, '00000000-0000-4000-8800-000000000002', fn (string $key): FlockOperation => $weighings->execute($individualFlock, [
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0'], ['weight' => '22.0'], ['weight' => '19.5']],
            'occurred_at' => now()->subDays(2)->toIso8601String(),
            'idempotency_key' => $key,
        ], $actor, 'seeder'));
        $this->onceOperation($actor, '00000000-0000-4000-8800-000000000003', fn (string $key): FlockOperation => $weighings->execute($individualFlock, [
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0'], ['weight' => '22.0'], ['weight' => '19.5'], ['weight' => '221.0']],
            'confirm_out_of_range' => true,
            'occurred_at' => now()->subDay()->toIso8601String(),
            'idempotency_key' => $key,
        ], $actor, 'seeder'));

        $groupFlock = Flock::query()->where('code', 'DEMO-LOT-D')->firstOrFail();
        $this->onceOperation($actor, '00000000-0000-4000-8800-000000000004', fn (string $key): FlockOperation => $weighings->execute($groupFlock, [
            'mode' => 'group',
            'unit' => 'g',
            'measurements' => [['total_weight' => '200.0', 'bird_count' => 10], ['total_weight' => '315.0', 'bird_count' => 15]],
            'occurred_at' => now()->subHours(12)->toIso8601String(),
            'idempotency_key' => $key,
        ], $actor, 'seeder'));
    }

    /** @param \Closure(string): FlockOperation $create */
    private function onceOperation(User $actor, string $key, \Closure $create): FlockOperation
    {
        return FlockOperation::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->first() ?? $create($key);
    }
}

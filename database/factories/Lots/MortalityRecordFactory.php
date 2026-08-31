<?php

namespace Database\Factories\Lots;

use App\Models\Lots\Flock;
use App\Models\Lots\MortalityCategory;
use App\Models\Lots\MortalityRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MortalityRecord> */
class MortalityRecordFactory extends Factory
{
    protected $model = MortalityRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'flock_id' => Flock::factory(),
            'poultry_house_id' => fn (array $data): int => Flock::query()->findOrFail($data['flock_id'])->poultry_house_id,
            'production_unit_id' => fn (array $data): int => Flock::query()->findOrFail($data['flock_id'])->production_unit_id,
            'mortality_category_id' => MortalityCategory::factory(),
            'quantity' => 2, 'occurred_at' => now()->startOfSecond(), 'status' => 'recorded', 'version' => 1, 'created_by' => User::factory(),
        ];
    }
}

<?php

namespace Database\Factories\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Modules\Lots\Domain\Enums\FlockStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Flock> */
class FlockFactory extends Factory
{
    protected $model = Flock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $entry = now(config('lots.timezone'))->subDays(30)->startOfDay();

        return [
            'public_id' => (string) Str::ulid(), 'code' => 'LOT-'.Str::upper(fake()->unique()->bothify('????####')),
            'breed_id' => Breed::factory(), 'supplier_id' => Supplier::factory(),
            'poultry_house_id' => PoultryHouse::factory(),
            'production_unit_id' => fn (array $data): int => PoultryHouse::query()->findOrFail($data['poultry_house_id'])->production_unit_id,
            'initial_quantity' => 100, 'current_quantity' => 100,
            'entry_date' => $entry->toDateString(), 'established_at' => $entry->copy()->utc(),
            'status' => FlockStatus::Active, 'version' => 1,
        ];
    }

    public function quarantined(): static
    {
        return $this->state(['status' => FlockStatus::Quarantined]);
    }

    public function finished(): static
    {
        return $this->state(['status' => FlockStatus::Finished, 'current_quantity' => 0, 'finalized_at' => now(), 'finalization_reason' => 'Fin de ciclo de prueba.']);
    }
}

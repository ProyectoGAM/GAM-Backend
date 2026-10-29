<?php

namespace Database\Seeders\Inventory;

use App\Actions\Inventory\AdjustFeedBalanceAction;
use App\Actions\Inventory\CancelFeedManufactureAction;
use App\Actions\Inventory\ManufactureFeedAction;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedRecipe;
use App\Models\User;
use Illuminate\Database\Seeder;

final class FeedManufactureDemoSeeder extends Seeder
{
    public function run(ManufactureFeedAction $manufacture, CancelFeedManufactureAction $cancel, AdjustFeedBalanceAction $adjust): void
    {
        if (! app()->environment('local') && ! (app()->environment('production') && config('app.demo_db_reset_enabled'))) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $recipe = FeedRecipe::query()->where('created_by', $actor->id)->where('idempotency_key', '01a133e2-061b-4a40-820a-93a472c6dc60')->sole();
        $plant = PoultryHouse::query()->where('normalized_name', 'planta de ración el ombú')->sole();
        $original = $manufacture->execute((int) $recipe->id, (int) $plant->id, 13000, '01a13431-2700-4000-8000-000000000001', $actor, 'seeder');
        $cancel->execute((int) $original->id, 'Cancelación de ejemplo para conservar los ingredientes de la demo.',
            '01a13431-2700-4000-8000-000000000002', $actor, 'seeder');
        $adjust->execute((int) $recipe->id, (int) $plant->production_unit_id, 'sumar', 26000,
            'Recuento ficticio independiente de alimento para probar la cuenta por receta y UP.',
            '01a13431-2700-4000-8000-000000000003', $actor, 'seeder');
    }
}

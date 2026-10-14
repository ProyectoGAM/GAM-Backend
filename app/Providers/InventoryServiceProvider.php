<?php

namespace App\Providers;

use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Policies\Inventory\FeedRecipePolicy;
use App\Policies\Inventory\InventoryMovementPolicy;
use App\Policies\Inventory\StockBalancePolicy;
use App\Policies\Inventory\StockLocationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(FeedRecipe::class, FeedRecipePolicy::class);
        Gate::policy(StockLocation::class, StockLocationPolicy::class);
        Gate::policy(StockBalance::class, StockBalancePolicy::class);
        Gate::policy(InventoryMovement::class, InventoryMovementPolicy::class);
    }
}

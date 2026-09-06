<?php

namespace App\Providers;

use App\Interfaces\Clock;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\Lots\Breed;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\MortalityCategory;
use App\Models\Lots\MortalityRecord;
use App\Policies\Lots\BreedPolicy;
use App\Policies\Lots\EggCollectionPolicy;
use App\Policies\Lots\FlockPolicy;
use App\Policies\Lots\MortalityCategoryPolicy;
use App\Policies\Lots\MortalityRecordPolicy;
use App\Services\Lots\LotsPoultryHouseOccupancyProvider;
use App\Services\SystemClock;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class LotsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->bind(PoultryHouseOccupancyProvider::class, LotsPoultryHouseOccupancyProvider::class);
    }

    public function boot(): void
    {
        Gate::policy(Flock::class, FlockPolicy::class);
        Gate::policy(Breed::class, BreedPolicy::class);
        Gate::policy(MortalityCategory::class, MortalityCategoryPolicy::class);
        Gate::policy(MortalityRecord::class, MortalityRecordPolicy::class);
        Gate::policy(EggCollection::class, EggCollectionPolicy::class);
    }
}

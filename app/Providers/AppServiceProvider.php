<?php

namespace App\Providers;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\Role;
use App\Models\Inventory\EggPresentation;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Lots\Breed;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\User;
use App\Policies\IdentityAndAccess\RolePolicy;
use App\Policies\IdentityAndAccess\UserPolicy;
use App\Policies\Inventory\FeedStockOperationPolicy;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\IdentityAndAccess\ProductionUnitScopeRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ProductionUnitAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(ProductionUnitScopeRegistry::class)->register();
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if ($user->usesDevicePin()) {
                foreach ($arguments as $subject) {
                    $class = is_object($subject) ? $subject::class : (is_string($subject) ? $subject : '');
                    $global = str_starts_with($class, 'App\\Models\\Geography\\') || str_starts_with($class, 'App\\Models\\SuppliersAndCatalogs\\')
                        || in_array($class, [Breed::class, WeighingReferenceSettings::class,
                            PlanTemplate::class, FeedRecipe::class, EggPresentation::class], true);
                    if ($global && ! in_array($ability, ['viewAny', 'view', 'calculate'], true)) {
                        return false;
                    }
                    if ($class === ProductionUnit::class && $ability === 'create') {
                        return false;
                    }
                    if ($subject instanceof Model
                        && ! app(ProductionUnitAccess::class)->allows($user, $subject)) {
                        return false;
                    }
                }
            }

            return null;
        });
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(FeedStockOperation::class, FeedStockOperationPolicy::class);

        RateLimiter::for('auth', function (Request $request): Limit {
            $email = Str::lower($request->string('email')->toString());

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth-account', fn (Request $request): Limit => Limit::perMinute(5)->by(
            'auth-account|'.Str::lower($request->string('email')->toString()),
        ));

        RateLimiter::for('pairing', fn (Request $request): Limit => Limit::perMinute(5)->by('pairing|'.$request->ip()));
        RateLimiter::for('pin', fn (Request $request): Limit => Limit::perMinute(30)->by('pin|'.$request->ip()));
        RateLimiter::for('credentials', fn (Request $request): Limit => Limit::perMinute(5)->by('credentials|'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('shared-device', function (Request $request): Limit {
            $device = $request->attributes->get('shared_device');

            return Limit::perMinute(30)->by('shared-device|'.($device?->getKey() ?? $request->ip()));
        });

        RateLimiter::for('reporting', function (Request $request): Limit {
            $actor = $request->user();
            $key = $actor === null ? $request->ip() : $actor->getAuthIdentifier();

            return Limit::perMinute(30)->by('reporting|'.$key);
        });

        RateLimiter::for('delivery-commands', function (Request $request): Limit {
            $actor = $request->user();
            $key = $actor === null ? $request->ip() : $actor->getAuthIdentifier();

            return Limit::perMinute(60)->by('delivery-command|'.$key);
        });

        RateLimiter::for('delivery-location', function (Request $request): Limit {
            $actor = $request->user();
            $delivery = $request->route('reparto');
            $deliveryKey = is_object($delivery) && method_exists($delivery, 'getRouteKey')
                ? $delivery->getRouteKey()
                : (string) $delivery;

            return Limit::perMinute(120)->by('delivery-location|'.($actor?->getAuthIdentifier() ?? $request->ip()).'|'.$deliveryKey);
        });
    }
}

<?php

namespace Database\Factories\ManagementPlans;

use App\Models\Inventory\FeedRecipe;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\ManagementPlans\PlanTemplateActivity;
use App\Models\ManagementPlans\PlanTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlanTemplate>
 */
class PlanTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'status' => 'active',
            'current_version' => 1,
            'created_by' => User::factory(),
        ];
    }

    public function published(): static
    {
        return $this->afterCreating(function ($template): void {
            $version = PlanTemplateVersion::factory()->for($template, 'template')->create([
                'number' => 1, 'status' => 'published', 'name' => $template->name,
                'description' => $template->description, 'published_at' => now(),
            ]);
            PlanTemplateActivity::factory()->for($version, 'version')->create();
            $recipe = FeedRecipe::factory()->create();
            PlanTemplateActivity::factory()->for($version, 'version')->create([
                'sort_order' => 2, 'type' => 'ration_change', 'title' => 'Alimentación inicial',
                'timing_kind' => 'day', 'start_day' => 1, 'start_week' => null,
                'feed_recipe_id' => $recipe->id, 'grams_per_bird' => 40,
                'catalog_type' => 'feed_recipe', 'catalog_id' => $recipe->id,
                'catalog_snapshot' => ['id' => $recipe->id, 'name' => $recipe->name],
            ]);
            $template->forceFill(['published_version' => 1])->save();
        });
    }
}

<?php

namespace Database\Factories\ManagementPlans;

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
            $template->forceFill(['published_version' => 1])->save();
        });
    }
}

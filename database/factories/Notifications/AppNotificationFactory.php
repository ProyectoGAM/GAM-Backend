<?php

namespace Database\Factories\Notifications;

use App\Models\Notifications\AppNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AppNotification> */
final class AppNotificationFactory extends Factory
{
    protected $model = AppNotification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'recipient_id' => User::factory(),
            'type' => 'test',
            'source_key' => (string) Str::uuid(),
            'title' => 'Aviso de prueba',
            'message' => 'Aviso disponible en la bandeja.',
            'occurred_at' => now(),
        ];
    }
}

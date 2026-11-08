<?php

namespace App\Http\Requests\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class ListOperationalAlertsRequest extends NotificationRequest
{
    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return [
            'up_id' => ['sometimes', 'integer', 'min:1', 'exists:production_units,id'],
            'urgencia' => ['sometimes', Rule::enum(NotificationUrgency::class)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}

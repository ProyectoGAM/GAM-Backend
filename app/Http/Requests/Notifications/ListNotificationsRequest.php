<?php

namespace App\Http\Requests\Notifications;

final class ListNotificationsRequest extends NotificationRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'estado_lectura' => ['sometimes', 'in:leida,no_leida'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}

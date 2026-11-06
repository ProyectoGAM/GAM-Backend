<?php

namespace App\Http\Requests\Notifications;

use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;

final class ListNotificationsRequest extends NotificationRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'estado_lectura' => ['sometimes', 'in:leida,no_leida'],
            'estado_resolucion' => ['sometimes', 'in:vigente,resuelto'],
            'urgencia' => ['sometimes', 'in:'.implode(',', array_column(NotificationUrgency::cases(), 'value'))],
            'modulo' => ['sometimes', 'string', 'regex:'.NotificationData::MODULE_PATTERN],
            'orden' => ['sometimes', 'in:fecha,urgencia'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'estado_resolucion.in' => 'El estado de resolución debe ser vigente o resuelto.',
            'urgencia.in' => 'La urgencia debe ser bajo, medio o alto.',
            'modulo.regex' => 'El módulo debe ser un código en minúsculas de hasta 80 caracteres.',
            'orden.in' => 'El orden debe ser fecha o urgencia.',
        ];
    }
}

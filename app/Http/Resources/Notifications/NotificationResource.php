<?php

namespace App\Http\Resources\Notifications;

use App\Models\Notifications\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppNotification */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'tipo' => $this->type,
            'urgencia' => $this->urgency->value,
            'modulo' => $this->module,
            'titulo' => $this->title,
            'mensaje' => $this->message,
            'fecha' => $this->occurred_at->toIso8601String(),
            'leida_en' => $this->read_at?->toIso8601String(),
            'estado_resolucion' => match (true) {
                ! $this->is_alert => null,
                $this->resolved_at === null => 'no_resuelto',
                default => 'resuelto',
            },
            'resuelta_en' => $this->resolved_at?->toIso8601String(),
            'entidad' => $this->entity_id === null ? null : [
                'tipo' => $this->entity_type,
                'id' => $this->entity_id,
                'ruta' => $this->target_path,
            ],
        ];
    }
}

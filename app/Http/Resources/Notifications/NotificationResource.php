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
            'titulo' => $this->title,
            'mensaje' => $this->message,
            'fecha' => $this->occurred_at->toIso8601String(),
            'leida_en' => $this->read_at?->toIso8601String(),
            'entidad' => $this->entity_id === null ? null : [
                'tipo' => $this->entity_type,
                'id' => $this->entity_id,
                'ruta' => $this->target_path,
            ],
        ];
    }
}

<?php

namespace App\Models\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use Database\Factories\Notifications\OperationalAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['public_id', 'production_unit_id', 'type', 'source_key', 'view_permission', 'entity_type', 'entity_id', 'target_path', 'title', 'urgency', 'occurred_at', 'resolved_at'])]
final class OperationalAlert extends Model
{
    /** @use HasFactory<OperationalAlertFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => OperationalAlertType::class,
            'urgency' => NotificationUrgency::class,
            'occurred_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}

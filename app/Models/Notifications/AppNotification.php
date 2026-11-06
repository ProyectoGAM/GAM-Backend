<?php

namespace App\Models\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use App\Models\User;
use Database\Factories\Notifications\AppNotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class AppNotification extends Model
{
    /** @use HasFactory<AppNotificationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'public_id', 'recipient_id', 'production_unit_id', 'type', 'source_key', 'urgency', 'module', 'title', 'message',
        'entity_type', 'entity_id', 'target_path', 'occurred_at', 'read_at',
    ];

    protected function casts(): array
    {
        return ['urgency' => NotificationUrgency::class, 'occurred_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
    }

    /** @return Builder<self> */
    public static function inboxFor(User $user): Builder
    {
        return self::query()->where('recipient_id', $user->getKey())
            ->when($user->usesDevicePin(), fn (Builder $query) => $query
                ->whereNotNull('production_unit_id')->where('production_unit_id', $user->production_unit_id));
    }
}

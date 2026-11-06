<?php

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\MarkNotificationReadAction;
use App\Enums\Notifications\NotificationUrgency;
use App\Http\Requests\Notifications\AuthorizeNotificationChannelRequest;
use App\Http\Requests\Notifications\ListNotificationsRequest;
use App\Http\Requests\Notifications\NotificationRequest;
use App\Http\Resources\Notifications\NotificationResource;
use App\Models\Notifications\AppNotification;
use App\Services\Notifications\NotificationChannel;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Broadcast;

final class NotificationController
{
    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        $query = AppNotification::inboxFor($request->user())
            ->when($request->validated('urgencia'), fn ($query, string $urgency) => $query->where('urgency', $urgency))
            ->when($request->validated('modulo'), fn ($query, string $module) => $query->where('module', $module));

        match ($request->validated('estado_lectura')) {
            'leida' => $query->whereNotNull('read_at'),
            'no_leida' => $query->whereNull('read_at'),
            default => null,
        };

        if ($request->validated('orden') === 'urgencia') {
            $query->orderByRaw('CASE urgency WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END', [
                NotificationUrgency::High->value,
                NotificationUrgency::Medium->value,
            ]);
        }

        return NotificationResource::collection($query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate((int) $request->validated('per_page', 20))->withQueryString());
    }

    public function summary(NotificationRequest $request, NotificationChannel $channels): JsonResponse
    {
        $channel = $channels->forRequest($request);
        $key = config('broadcasting.connections.reverb.key');

        return response()->json(['data' => [
            'no_leidas' => AppNotification::inboxFor($request->user())->whereNull('read_at')->count(),
            'tiempo_real' => config('broadcasting.default') === 'reverb' && is_string($key) && $key !== '' && $channel !== null
                ? ['clave' => $key, 'canal' => $channel]
                : null,
        ]]);
    }

    public function show(NotificationRequest $request, string $notificacion): NotificationResource
    {
        return new NotificationResource(AppNotification::inboxFor($request->user())->where('public_id', $notificacion)->firstOrFail());
    }

    public function markRead(NotificationRequest $request, string $notificacion, MarkNotificationReadAction $action): NotificationResource
    {
        return new NotificationResource($action->execute($request->user(), $notificacion));
    }

    public function authorizeChannel(AuthorizeNotificationChannelRequest $request, NotificationChannel $channels): JsonResponse
    {
        $channel = $channels->forRequest($request);
        abort_unless(config('broadcasting.default') === 'reverb' && $channel !== null
            && hash_equals('private-'.$channel, $request->validated('channel_name')), 403);

        /** @var PusherBroadcaster $broadcaster */
        $broadcaster = Broadcast::connection('reverb');

        return response()->json($broadcaster->validAuthenticationResponse($request, true));
    }
}

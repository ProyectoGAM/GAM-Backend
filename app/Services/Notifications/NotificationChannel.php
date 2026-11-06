<?php

namespace App\Services\Notifications;

use App\Models\IdentityAndAccess\AuthSession;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;

final class NotificationChannel
{
    public function __construct(private AuthSessionService $sessions) {}

    public function forRequest(Request $request): ?string
    {
        $session = $this->sessions->current($request);

        return $session?->isUsable() && (int) $session->user_id === $request->user()?->id
            ? $this->name($session)
            : null;
    }

    /** @return list<PrivateChannel> */
    public function forRecipient(int $recipientId, ?int $unitId): array
    {
        $user = User::query()->find($recipientId);
        if ($user === null || ! $user->hasAccessRole() || ! $user->can('can_view_notifications')) {
            return [];
        }

        $sessions = AuthSession::query()->where('user_id', $recipientId)
            ->whereNull('revoked_at')->where('expires_at', '>', now());

        if ($user->usesDevicePin()) {
            if ($unitId === null || $user->production_unit_id !== $unitId || ! $user->pin_enabled
                || $user->hasRole('admin') || ! $user->can('can_login_shared')) {
                return [];
            }

            $sessions->where('kind', 'shared_user')->where('production_unit_id', $unitId)
                ->where(fn ($query) => $query->whereNull('last_user_activity_at')
                    ->orWhere('last_user_activity_at', '>=', now()->subSeconds((int) config('identity.shared_idle_seconds', 120))))
                ->whereHas('sharedDevice', fn ($query) => $query
                    ->where('production_unit_id', $unitId)
                    ->whereColumn('shared_devices.session_generation', 'auth_sessions.device_generation')
                    ->whereNull('revoked_at')->where('credential_expires_at', '>', now())
                    ->whereHas('productionUnit', fn ($unit) => $unit->where('status', 'active')));
        } else {
            $transports = array_keys(array_filter([
                'cookie' => $user->can('can_login_web'),
                'bearer' => $user->can('can_login_native'),
            ]));
            $sessions->where('kind', 'personal')->whereIn('transport', $transports);
        }

        return $sessions->get(['id'])->map(fn (AuthSession $session) => new PrivateChannel($this->name($session)))->all();
    }

    private function name(AuthSession $session): string
    {
        return 'notifications.'.hash('sha256', (string) $session->getKey());
    }
}

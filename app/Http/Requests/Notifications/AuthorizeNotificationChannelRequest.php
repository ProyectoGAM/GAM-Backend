<?php

namespace App\Http\Requests\Notifications;

final class AuthorizeNotificationChannelRequest extends NotificationRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/D'],
            'channel_name' => ['required', 'string', 'regex:/^private-notifications\.[a-f0-9]{64}$/D'],
        ];
    }
}

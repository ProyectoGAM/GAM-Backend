<?php

namespace App\Http\Requests\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Models\User;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Validation\Rule;

#[FailOnUnknownFields]
final class UpdateAlertConfigurationRequest extends ShowAlertConfigurationRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var OperationalAlertType $type */
        $type = $this->route('operationalAlertType');
        $rules = [
            'habilitada' => ['sometimes', 'required', 'boolean'],
            'urgencia_fija' => ['sometimes', 'nullable', Rule::enum(NotificationUrgency::class)],
        ];
        foreach ($type->thresholds() as $key => $field) {
            $rules[$key] = ['sometimes', 'required', 'integer', 'min:'.$field['minimo'], 'max:'.$field['maximo']];
        }

        return $rules;
    }

    public function actor(): User
    {
        /** @var User $actor */
        $actor = $this->user();

        return $actor;
    }

    /** @return array<string, bool|int|string|null> */
    public function changes(): array
    {
        $changes = $this->validated();
        foreach ($changes as $key => $value) {
            $changes[$key] = match ($key) {
                'habilitada' => (bool) $value,
                'urgencia_fija' => $value,
                default => (int) $value,
            };
        }

        return $changes;
    }
}

<?php

namespace App\Http\Requests\Notifications;

use App\Enums\Notifications\AlertTimingType;
use App\Models\User;
use App\ValueObjects\Notifications\AlertTimingThresholds;
use Closure;
use Illuminate\Validation\Validator;

final class UpdateAlertTimingThresholdsRequest extends ShowAlertTimingThresholdsRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        /** @var AlertTimingType $type */
        $type = $this->route('alertType');
        $limits = ['integer', 'min:'.$type->minimumDays(), 'max:365'];

        return [
            'bajo' => $type === AlertTimingType::ManualFeedingReviewDue ? ['present', 'prohibited'] : ['present', 'nullable', ...$limits],
            'medio' => $type === AlertTimingType::ManualFeedingReviewDue ? ['required', ...$limits] : ['present', 'nullable', ...$limits],
            'alto' => ['required', ...$limits],
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff_key($this->all(), $this->rules()) as $key => $_) {
                $validator->errors()->add($key, 'El campo no está permitido en esta operación.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $previous = null;
            foreach (['bajo', 'medio', 'alto'] as $level) {
                $value = $this->input($level);
                if ($value === null) {
                    continue;
                }
                if ($previous !== null && (int) $value <= $previous) {
                    $validator->errors()->add($level, 'Cada nivel activo debe comenzar después del nivel anterior.');
                }
                $previous = (int) $value;
            }
        }];
    }

    public function thresholds(): AlertTimingThresholds
    {
        $data = $this->validated();

        return new AlertTimingThresholds(
            $data['bajo'] === null ? null : (int) $data['bajo'],
            $data['medio'] === null ? null : (int) $data['medio'],
            (int) $data['alto'],
        );
    }

    public function actor(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }
}

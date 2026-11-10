<?php

namespace App\Http\Requests\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\ManualPractice;
use App\Services\Lots\LotsAuthorization;

final class CorrectManagementExecutionRequest extends ManagementExecutionRequest
{
    public function authorize(): bool
    {
        $flock = $this->route('flock');
        if (! $flock instanceof Flock || ! $this->authorizeFlockExecution()) {
            return false;
        }

        return $this->route('executionType') !== 'manual_practice'
            || ! ManualPractice::query()->where('flock_id', $flock->id)
                ->where('public_id', $this->route('execution'))->where('practice_type', 'beak_trimming')->exists()
            || LotsAuthorization::allows($this->actor(), 'can_reverse_beak_trimming');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}

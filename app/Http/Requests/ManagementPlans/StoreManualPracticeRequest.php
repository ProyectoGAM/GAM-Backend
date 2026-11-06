<?php

namespace App\Http\Requests\ManagementPlans;

use App\Services\Lots\LotsAuthorization;
use Illuminate\Validation\Validator;

final class StoreManualPracticeRequest extends ManagementExecutionRequest
{
    public function authorize(): bool
    {
        return $this->authorizeFlockExecution()
            && ($this->input('practice_type') !== 'beak_trimming'
                || LotsAuthorization::allows($this->actor(), 'can_record_beak_trimming'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            ...$this->activityLinkRules(),
            'practice_type' => ['required', 'string', 'in:beak_trimming,nest_placement,other'],
            'title' => ['required_if:practice_type,other', 'nullable', 'string', 'min:1', 'max:200'],
            'notes' => ['required_unless:practice_type,beak_trimming', 'nullable', 'string', 'min:1', 'max:5000'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (($this->input('practice_type') ?? null) !== 'other' && $this->exists('title')) {
                $validator->errors()->add('title', 'El título sólo se permite para otras prácticas.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        if (isset($data['responsible_user_id'])) {
            $data['responsible_user_id'] = (int) $data['responsible_user_id'];
        }

        return $data;
    }
}

<?php

namespace App\Http\Requests\Notifications;

use App\Models\User;
use App\Services\Lots\LotsAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class ShowAlertTimingThresholdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && LotsAuthorization::allows($user, 'can_manage_alert_thresholds');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Requests\Notifications;

use App\Enums\Notifications\OperationalAlertType;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Lots\LotsAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class ShowAlertConfigurationRequest extends FormRequest
{
    public function authorize(ProductionUnitAccess $access): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User || ! LotsAuthorization::allows($actor, 'can_manage_alert_thresholds')) {
            return false;
        }

        $unit = $this->route('productionUnit');
        $type = $this->route('operationalAlertType');
        $unitId = $unit instanceof ProductionUnit ? $unit->id : null;
        abort_if($type instanceof OperationalAlertType && ! $type->appliesTo($unitId), 404);

        if ($unitId === null) {
            return ! $actor->usesDevicePin();
        }
        $access->assertUnit($unitId, $actor);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}

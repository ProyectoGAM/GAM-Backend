<?php

namespace App\Services\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\Lots\Breed;
use App\Models\User;
use Illuminate\Support\Str;

final class AuditSubjectNavigation
{
    /**
     * Proyecta referencias históricas sin consultar los recursos en cada fila.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{label:?string, resource:?string, id:int|string|null, section?:string, record_id?:string}|null
     */
    public function subject(AuditEntry $entry, array $snapshot, User $viewer): ?array
    {
        $properties = $entry->properties?->all() ?? [];
        if ($entry->log_name === 'vehicles') {
            $section = match ($entry->event) {
                'vehicle_created', 'vehicle_updated', 'vehicle_status_changed' => 'overview',
                'vehicle_meter_reading_recorded', 'vehicle_meter_reading_corrected', 'vehicle_meter_reset_recorded' => 'readings',
                'vehicle_usage_started', 'vehicle_usage_closed' => 'usages',
                'vehicle_fuel_recorded' => 'fuel',
                'vehicle_maintenance_plan_created', 'vehicle_maintenance_plan_updated' => 'maintenance_plans',
                'vehicle_maintenance_created', 'vehicle_maintenance_start', 'vehicle_maintenance_complete',
                'vehicle_maintenance_cancel', 'vehicle_maintenance_document_uploaded' => 'maintenance',
                'vehicle_document_uploaded' => 'documents',
                default => null,
            };
            if ($section === null) {
                return null;
            }

            $overview = $section === 'overview';
            $id = $overview ? ($snapshot['id'] ?? null) : ($properties['vehicle_id'] ?? data_get($properties, 'vehicle_snapshot.id'));
            $label = $overview ? ($snapshot['plate'] ?? null) : data_get($properties, 'vehicle_snapshot.plate');

            return $this->destination($label, 'vehicle', Str::isUlid($id) && $this->canViewVehicleSection($viewer, $section) ? $id : null, $section);
        }

        if ($entry->log_name === 'customers_and_accounts' && str_starts_with((string) $entry->event, 'customer_account_')) {
            $id = $snapshot['customer_id'] ?? null;
            $subject = $this->destination($snapshot['customer_name'] ?? null, 'customer',
                Str::isUlid($id) && ! $viewer->usesDevicePin() && $viewer->can('account', Customer::class) ? $id : null, 'account');
            $recordId = $snapshot['id'] ?? null;
            if ($subject['id'] !== null && Str::isUlid($recordId)) {
                $subject['record_id'] = $recordId;
            }

            return $subject;
        }
        if ($entry->log_name === 'customers_and_accounts' && in_array($entry->event, ['customer_created', 'customer_updated', 'customer_status_changed'], true)) {
            $id = $snapshot['id'] ?? data_get($properties, 'presentation_subject.id');

            return $this->destination($snapshot['name'] ?? data_get($properties, 'presentation_subject.label'), 'customer',
                Str::isUlid($id) && ! $viewer->usesDevicePin() && $viewer->can('viewAny', Customer::class) ? $id : null, 'profile');
        }
        if (in_array($entry->log_name, ['lots', 'management_plans'], true) && in_array($entry->event, AuditEventCatalog::BREED_EVENTS, true)) {
            $id = $snapshot['id'] ?? null;

            return $this->destination($snapshot['name'] ?? null, 'breed',
                is_int($id) && $id > 0 && $viewer->can('viewAny', Breed::class) ? $id : null, 'catalog');
        }

        return null;
    }

    public function canViewVehicleSection(User $viewer, string $section): bool
    {
        if ($viewer->usesDevicePin()) {
            return false;
        }

        $permissions = match ($section) {
            'fuel' => ['can_view_vehicle_fuel', 'can_manage_vehicle_fuel'],
            'maintenance', 'maintenance_plans' => ['can_view_vehicle_maintenance', 'can_manage_vehicle_maintenance'],
            'documents' => ['can_view_vehicle_documents', 'can_manage_vehicle_documents'],
            'overview' => ['can_view_vehicles', 'can_manage_vehicles', 'can_manage_vehicle_readings', 'can_use_vehicles',
                'can_view_vehicle_fuel', 'can_manage_vehicle_fuel', 'can_view_vehicle_maintenance', 'can_manage_vehicle_maintenance',
                'can_view_vehicle_documents', 'can_manage_vehicle_documents'],
            'readings', 'usages' => ['can_view_vehicles', 'can_manage_vehicles'],
            default => [],
        };

        return $viewer->canAny($permissions);
    }

    /** @return array{label:?string, resource:?string, id:int|string|null, section?:string} */
    private function destination(mixed $label, string $resource, int|string|null $id, string $section): array
    {
        $label = is_string($label) ? trim(strip_tags($label)) : null;

        return $id === null
            ? ['label' => $label, 'resource' => null, 'id' => null]
            : ['label' => $label, 'resource' => $resource, 'id' => $id, 'section' => $section];
    }
}

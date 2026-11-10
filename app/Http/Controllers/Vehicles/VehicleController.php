<?php

namespace App\Http\Controllers\Vehicles;

use App\Actions\Vehicles\ChangeVehicleStatusAction;
use App\Actions\Vehicles\CloseVehicleUsageAction;
use App\Actions\Vehicles\ManageVehicleMaintenanceAction;
use App\Actions\Vehicles\RecordVehicleFuelAction;
use App\Actions\Vehicles\RecordVehicleReadingAction;
use App\Actions\Vehicles\SaveVehicleAction;
use App\Actions\Vehicles\SaveVehicleMaintenancePlanAction;
use App\Actions\Vehicles\StartVehicleUsageAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Http\Requests\Vehicles\VehicleAvailableRequest;
use App\Http\Requests\Vehicles\VehicleCommandRequest;
use App\Http\Requests\Vehicles\VehicleDriversRequest;
use App\Http\Requests\Vehicles\VehicleIndexRequest;
use App\Http\Requests\Vehicles\VehicleReadRequest;
use App\Http\Resources\Vehicles\VehicleResource;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleDocument;
use App\Models\Vehicles\VehicleFuelEntry;
use App\Models\Vehicles\VehicleMaintenance;
use App\Models\Vehicles\VehicleMaintenanceDocument;
use App\Models\Vehicles\VehicleMaintenancePlan;
use App\Models\Vehicles\VehicleUsage;
use App\Services\Vehicles\VehicleAvailability;
use App\Services\Vehicles\VehicleMaintenanceSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class VehicleController
{
    public function drivers(VehicleDriversRequest $request): JsonResponse
    {
        $drivers = User::query()->permission('can_start_deliveries')
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $drivers]);
    }

    public function index(VehicleIndexRequest $request, VehicleAvailability $availability): AnonymousResourceCollection
    {
        $data = $request->validated();
        $query = Vehicle::query()->with('habitualDriver:id,name')
            ->when($data['search'] ?? null, fn ($query, string $search) => $query->where(fn ($nested) => $nested->where('plate', 'ilike', '%'.$search.'%')->orWhere('make', 'ilike', '%'.$search.'%')->orWhere('model', 'ilike', '%'.$search.'%')))
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('plate');
        $perPage = (int) ($data['per_page'] ?? 24);
        $page = (int) ($data['page'] ?? 1);
        if (isset($data['availability'])) {
            $matching = $query->get()->filter(function (Vehicle $vehicle) use ($availability, $data): bool {
                $state = $availability->describe($vehicle);
                $vehicle->setAttribute('vehicle_availability', $state);

                return $state['availability'] === $data['availability'];
            });
            $vehicles = new LengthAwarePaginator(
                $matching->forPage($page, $perPage)->values(),
                $matching->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $vehicles = $query->paginate($perPage);
        }
        $vehicles->getCollection()->transform(function (Vehicle $vehicle) use ($availability): Vehicle {
            $latest = $vehicle->readings()->effective()->where('meter_type', 'odometer')->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $vehicle->setAttribute('latest_odometer', $latest?->value);
            $vehicle->setAttribute('latest_odometer_at', $latest?->recorded_at);
            $vehicle->setAttribute('vehicle_availability', $vehicle->getAttribute('vehicle_availability') ?? $availability->describe($vehicle));

            return $vehicle;
        });

        return VehicleResource::collection($vehicles);
    }

    public function available(VehicleAvailableRequest $request, VehicleAvailability $availability): JsonResponse
    {
        $vehicles = Vehicle::query()->where('status', 'active')->with('habitualDriver:id,name')
            ->when($request->validated('search'), fn ($query, string $search) => $query->where(fn ($nested) => $nested->where('plate', 'ilike', '%'.$search.'%')->orWhere('make', 'ilike', '%'.$search.'%')->orWhere('model', 'ilike', '%'.$search.'%')))
            ->orderBy('plate')->get();
        $data = $vehicles->map(function (Vehicle $vehicle) use ($availability): array {
            $state = $availability->describe($vehicle);

            return ['id' => $vehicle->public_id, 'plate' => $vehicle->plate, 'make' => $vehicle->make, 'model' => $vehicle->model, 'availability' => $state['availability'], 'warnings' => $state['warnings']];
        })->filter(fn (array $vehicle): bool => $vehicle['availability'] === 'available')->values();

        return response()->json(['data' => $data]);
    }

    public function store(VehicleCommandRequest $request, SaveVehicleAction $action): JsonResponse
    {
        $vehicle = $action->execute($request->validated(), $this->actor($request));

        return (new VehicleResource($vehicle))->response()->setStatusCode(201);
    }

    public function show(VehicleReadRequest $request, Vehicle $vehiculo, VehicleAvailability $availability): JsonResponse
    {
        $user = $request->user();
        $canReadFuel = $user?->can('can_view_vehicle_fuel') || $user?->can('can_manage_vehicle_fuel');
        $canReadMaintenance = $user?->can('can_view_vehicle_maintenance') || $user?->can('can_manage_vehicle_maintenance');
        $canReadDocuments = $user?->can('can_view_vehicle_documents') || $user?->can('can_manage_vehicle_documents');
        $vehicle = $vehiculo->load(['habitualDriver:id,name', 'statusChanges' => fn ($query) => $query->with('actor:id,name')->latest('occurred_at')->limit(50), 'usages' => fn ($query) => $query->with(['driver:id,name', 'delivery:id,public_id'])->latest('started_at')->limit(50), 'readings' => fn ($query) => $query->where('meter_type', 'odometer')->with('correctsReading')->latest('recorded_at')->orderByDesc('id')->limit(50), 'fuelEntries' => fn ($query) => $query->with('usage')->latest('occurred_at')->limit(50), 'maintenancePlans' => fn ($query) => $query->where('active', true)->orderBy('task'), 'maintenances' => fn ($query) => $query->with(['items.plan', 'documents'])->latest()->limit(50), 'documents' => fn ($query) => $query->latest()]);
        $latest = $vehicle->readings()->effective()->where('meter_type', 'odometer')->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $vehicle->setAttribute('latest_odometer', $latest?->value);
        $vehicle->setAttribute('latest_odometer_at', $latest?->recorded_at);
        $odometer = $vehicle->readings()->effective()->where('meter_type', 'odometer')->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $maintenanceSchedule = app(VehicleMaintenanceSchedule::class);
        $maintenanceState = $vehicle->maintenancePlans->map(function (VehicleMaintenancePlan $plan) use ($maintenanceSchedule, $odometer): array {
            $status = $maintenanceSchedule->status($plan, $odometer);

            return ['id' => $plan->public_id, 'task' => $plan->task, 'interval_days' => $plan->interval_days, 'interval_km' => $plan->interval_km, 'next_due_at' => $plan->next_due_at?->toDateString(), 'next_due_odometer_km' => $plan->next_due_odometer_km, 'next_due_odometer_epoch' => $plan->next_due_odometer_epoch, 'status' => $status, 'version' => $plan->version];
        });

        return response()->json([
            'data' => (new VehicleResource($vehicle))->resolve($request),
            'status_changes' => $vehicle->statusChanges->map(fn ($change): array => [
                'id' => $change->public_id, 'from_status' => $change->from_status, 'to_status' => $change->to_status,
                'reason' => $change->reason, 'occurred_at' => $change->occurred_at, 'actor_name' => $change->actor?->name,
            ]),
            'usages' => $vehicle->usages->map(fn (VehicleUsage $usage): array => [
                'id' => $usage->public_id, 'purpose' => $usage->purpose, 'driver_snapshot' => $usage->driver_snapshot,
                'started_at' => $usage->started_at, 'ended_at' => $usage->ended_at,
                'start_odometer_km' => $usage->start_odometer_km, 'end_odometer_km' => $usage->end_odometer_km,
                'vehicle_snapshot' => $usage->vehicle_snapshot, 'delivery_id' => $usage->delivery?->public_id,
            ]),
            'readings' => $vehicle->readings->map(fn ($reading): array => [
                'id' => $reading->public_id, 'meter_type' => $reading->meter_type, 'value' => $reading->value,
                'event_type' => $reading->event_type, 'event_reason' => $reading->event_reason,
                'corrects_reading_id' => $reading->correctsReading?->public_id,
                'recorded_at' => $reading->recorded_at, 'received_at' => $reading->received_at, 'source' => $reading->source,
                'notes' => $reading->notes, 'correction_reason' => $reading->correction_reason,
            ]),
            'fuel_entries' => $canReadFuel ? $vehicle->fuelEntries->map(fn ($entry): array => [
                'id' => $entry->public_id, 'occurred_at' => $entry->occurred_at, 'liters' => $entry->liters,
                'fuel_type' => $entry->fuel_type, 'amount' => $entry->amount, 'currency' => $entry->currency,
                'supplier' => $entry->supplier, 'odometer_km' => $entry->odometer_km, 'notes' => $entry->notes,
                'receipt_url' => $entry->receipt_path ? route('api.v1.vehicles.fuel.receipt', [$vehicle, $entry]) : null,
                'receipt_name' => $entry->receipt_original_name,
            ]) : [],
            'maintenance_plans' => $canReadMaintenance ? $maintenanceState : [],
            'maintenances' => $canReadMaintenance ? $vehicle->maintenances->map(fn (VehicleMaintenance $maintenance): array => [
                'id' => $maintenance->public_id, 'type' => $maintenance->type, 'status' => $maintenance->status,
                'immobilizes_vehicle' => $maintenance->immobilizes_vehicle, 'workshop' => $maintenance->workshop,
                'description' => $maintenance->description, 'planned_at' => $maintenance->planned_at,
                'started_at' => $maintenance->started_at, 'completed_at' => $maintenance->completed_at,
                'odometer_km' => $maintenance->odometer_km,
                'cost_amount' => $maintenance->cost_amount, 'cost_currency' => $maintenance->cost_currency,
                'version' => $maintenance->version,
                'items' => $maintenance->items->map(fn ($item): array => ['id' => $item->id, 'public_id' => $item->public_id, 'plan_id' => $item->plan_id, 'task' => $item->task, 'completed' => $item->completed, 'notes' => $item->notes]),
                'documents' => $maintenance->documents->map(fn (VehicleMaintenanceDocument $document): array => ['id' => $document->public_id, 'title' => $document->title, 'original_name' => $document->original_name, 'download_url' => route('api.v1.vehicles.maintenances.documents.download', [$vehicle, $maintenance, $document])]),
            ]) : [],
            'documents' => $canReadDocuments ? $vehicle->documents->map(fn (VehicleDocument $document): array => ['id' => $document->public_id, 'type' => $document->type, 'title' => $document->title, 'expires_at' => $document->expires_at?->toDateString(), 'original_name' => $document->original_name, 'download_url' => route('api.v1.vehicles.documents.download', [$vehicle, $document])]) : [],
            'availability' => $availability->describe($vehicle),
        ]);
    }

    public function update(VehicleCommandRequest $request, Vehicle $vehiculo, SaveVehicleAction $action): VehicleResource
    {
        return new VehicleResource($action->execute($request->validated(), $this->actor($request), $vehiculo));
    }

    public function status(VehicleCommandRequest $request, Vehicle $vehiculo, ChangeVehicleStatusAction $action): VehicleResource
    {
        return new VehicleResource($action->execute($vehiculo, $request->validated('status'), $request->validated('reason'), $this->actor($request)));
    }

    public function readings(VehicleReadRequest $request, Vehicle $vehiculo): JsonResponse
    {
        $readings = $vehiculo->readings()->where('meter_type', 'odometer')->with('correctsReading')->latest('recorded_at')->orderByDesc('id')->paginate((int) $request->validated('per_page', 30));

        return response()->json([
            'data' => $readings->getCollection()->map(fn ($reading): array => [
                'id' => $reading->public_id,
                'meter_type' => $reading->meter_type,
                'event_type' => $reading->event_type,
                'event_reason' => $reading->event_reason,
                'corrects_reading_id' => $reading->correctsReading?->public_id,
                'value' => $reading->value,
                'counter_epoch' => $reading->counter_epoch,
                'recorded_at' => $reading->recorded_at,
                'received_at' => $reading->received_at,
                'source' => $reading->source,
                'notes' => $reading->notes,
                'correction_reason' => $reading->correction_reason,
            ]),
            'meta' => ['current_page' => $readings->currentPage(), 'last_page' => $readings->lastPage(), 'per_page' => $readings->perPage(), 'total' => $readings->total()],
        ]);
    }

    public function recordReading(VehicleCommandRequest $request, Vehicle $vehiculo, RecordVehicleReadingAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($vehiculo, $request->validated(), $this->actor($request))], 201);
    }

    public function usages(VehicleReadRequest $request, Vehicle $vehiculo): JsonResponse
    {
        return response()->json(['data' => $vehiculo->usages()->with('driver:id,name')->latest('started_at')->paginate((int) $request->validated('per_page', 30))]);
    }

    public function startUsage(VehicleCommandRequest $request, Vehicle $vehiculo, StartVehicleUsageAction $action): JsonResponse
    {
        $data = $request->validated();
        $actor = $this->actor($request);
        $driver = isset($data['driver_id']) ? User::query()->whereKey($data['driver_id'])->permission('can_start_deliveries')->firstOrFail() : $actor;

        return response()->json(['data' => $action->execute($vehiculo, $driver, $data, $data['idempotency_key'], actor: $actor)], 201);
    }

    public function closeUsage(VehicleCommandRequest $request, VehicleUsage $uso, CloseVehicleUsageAction $action): JsonResponse
    {
        abort_unless($uso->delivery_id === null, 409, 'Los usos asociados a repartos se cierran junto con el reparto.');

        return response()->json(['data' => $action->execute($uso, $request->validated(), $this->actor($request))]);
    }

    public function fuel(VehicleReadRequest $request, Vehicle $vehiculo): JsonResponse
    {
        $entries = $vehiculo->fuelEntries()->with('usage')->latest('occurred_at')->paginate((int) $request->validated('per_page', 30));
        $entries->setCollection($entries->getCollection()->map(fn (VehicleFuelEntry $entry): array => [
            'id' => $entry->public_id, 'occurred_at' => $entry->occurred_at, 'liters' => $entry->liters,
            'fuel_type' => $entry->fuel_type, 'amount' => $entry->amount, 'currency' => $entry->currency,
            'supplier' => $entry->supplier, 'odometer_km' => $entry->odometer_km, 'notes' => $entry->notes,
            'receipt_url' => $entry->receipt_path ? route('api.v1.vehicles.fuel.receipt', [$vehiculo, $entry]) : null,
            'receipt_name' => $entry->receipt_original_name,
        ]));

        return response()->json(['data' => $entries]);
    }

    public function recordFuel(VehicleCommandRequest $request, Vehicle $vehiculo, RecordVehicleFuelAction $action): JsonResponse
    {
        $data = $request->validated();
        $storedPath = null;
        if ($request->hasFile('receipt')) {
            $receipt = $request->file('receipt');
            $storedPath = $receipt->store('vehicles/'.$vehiculo->public_id.'/fuel', 'local');
            $hash = hash_file('sha256', $receipt->getRealPath());
            if (! is_string($storedPath) || ! is_string($hash)) {
                if (is_string($storedPath)) {
                    Storage::disk('local')->delete($storedPath);
                }
                throw new \RuntimeException('No se pudo almacenar el comprobante de combustible.');
            }
            $data['receipt_path'] = $storedPath;
            $data['receipt_sha256'] = $hash;
            $data['receipt_original_name'] = $receipt->getClientOriginalName();
        }
        unset($data['receipt']);
        try {
            $entry = $action->execute($vehiculo, $data, $this->actor($request));
        } catch (\Throwable $error) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $error;
        }
        if ($storedPath !== null && $entry->receipt_path !== $storedPath) {
            Storage::disk('local')->delete($storedPath);
        }

        return response()->json(['data' => ['id' => $entry->public_id, 'vehicle_id' => $vehiculo->public_id, 'occurred_at' => $entry->occurred_at, 'liters' => $entry->liters, 'fuel_type' => $entry->fuel_type, 'amount' => $entry->amount, 'currency' => $entry->currency, 'supplier' => $entry->supplier, 'odometer_km' => $entry->odometer_km, 'receipt_url' => $entry->receipt_path ? route('api.v1.vehicles.fuel.receipt', [$vehiculo, $entry]) : null, 'receipt_name' => $entry->receipt_original_name]], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function downloadFuelReceipt(VehicleReadRequest $request, Vehicle $vehiculo, VehicleFuelEntry $combustible): StreamedResponse
    {
        abort_unless($combustible->vehicle_id === $vehiculo->getKey() && $combustible->receipt_path !== null && Storage::disk('local')->exists($combustible->receipt_path), 404);

        return Storage::disk('local')->download($combustible->receipt_path, $combustible->receipt_original_name ?? basename($combustible->receipt_path));
    }

    public function plans(VehicleReadRequest $request, Vehicle $vehiculo): JsonResponse
    {
        return response()->json(['data' => $vehiculo->maintenancePlans()->orderBy('task')->paginate((int) $request->validated('per_page', 30))]);
    }

    public function createPlan(VehicleCommandRequest $request, Vehicle $vehiculo, SaveVehicleMaintenancePlanAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($vehiculo, $request->validated(), $this->actor($request))], 201);
    }

    public function updatePlan(VehicleCommandRequest $request, Vehicle $vehiculo, VehicleMaintenancePlan $plan, SaveVehicleMaintenancePlanAction $action): JsonResponse
    {
        abort_unless($plan->vehicle_id === $vehiculo->getKey(), 404);

        return response()->json(['data' => $action->execute($vehiculo, $request->validated(), $this->actor($request), $plan)]);
    }

    public function maintenances(VehicleReadRequest $request, Vehicle $vehiculo): JsonResponse
    {
        return response()->json(['data' => $vehiculo->maintenances()->with('items.plan')->latest()->paginate((int) $request->validated('per_page', 30))]);
    }

    public function createMaintenance(VehicleCommandRequest $request, Vehicle $vehiculo, ManageVehicleMaintenanceAction $action): JsonResponse
    {
        return response()->json(['data' => $action->create($vehiculo, $request->validated(), $this->actor($request))], 201);
    }

    public function startMaintenance(VehicleCommandRequest $request, VehicleMaintenance $mantenimiento, ManageVehicleMaintenanceAction $action): JsonResponse
    {
        return response()->json(['data' => $action->transition($mantenimiento, 'start', $request->validated(), $this->actor($request))]);
    }

    public function completeMaintenance(VehicleCommandRequest $request, VehicleMaintenance $mantenimiento, ManageVehicleMaintenanceAction $action): JsonResponse
    {
        return response()->json(['data' => $action->transition($mantenimiento, 'complete', $request->validated(), $this->actor($request))]);
    }

    public function cancelMaintenance(VehicleCommandRequest $request, VehicleMaintenance $mantenimiento, ManageVehicleMaintenanceAction $action): JsonResponse
    {
        return response()->json(['data' => $action->transition($mantenimiento, 'cancel', $request->validated(), $this->actor($request))]);
    }

    public function uploadMaintenanceDocument(VehicleCommandRequest $request, Vehicle $vehiculo, VehicleMaintenance $mantenimiento, AuditRecorder $audit): JsonResponse
    {
        abort_unless($mantenimiento->vehicle_id === $vehiculo->getKey(), 404);
        $file = $request->file('file');
        $path = $file->store('vehicles/'.$vehiculo->public_id.'/maintenance/'.$mantenimiento->public_id, 'local');
        if (! is_string($path)) {
            throw new \RuntimeException('No se pudo almacenar el comprobante de mantenimiento.');
        }
        try {
            $document = DB::transaction(function () use ($vehiculo, $mantenimiento, $file, $path, $request, $audit): VehicleMaintenanceDocument {
                $record = $mantenimiento->documents()->create([
                    'uploaded_by' => $this->actor($request)->getKey(), 'title' => $file->getClientOriginalName(),
                    'storage_path' => $path, 'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                ]);
                $audit->record(AuditEntryData::forSubject($record, $this->actor($request), 'vehicles', 'vehicle_maintenance_document_uploaded', 'Comprobante privado de mantenimiento cargado', ['vehicle_id' => $vehiculo->public_id, 'maintenance_id' => $mantenimiento->public_id, 'original_name' => $record->original_name], source: 'api'));

                return $record;
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return response()->json(['data' => ['id' => $document->public_id, 'title' => $document->title, 'original_name' => $document->original_name]], 201);
    }

    public function downloadMaintenanceDocument(VehicleReadRequest $request, Vehicle $vehiculo, VehicleMaintenance $mantenimiento, VehicleMaintenanceDocument $documento): StreamedResponse
    {
        abort_unless($mantenimiento->vehicle_id === $vehiculo->getKey() && $documento->maintenance_id === $mantenimiento->getKey() && Storage::disk('local')->exists($documento->storage_path), 404);

        return Storage::disk('local')->download($documento->storage_path, $documento->original_name);
    }

    public function uploadDocument(VehicleCommandRequest $request, Vehicle $vehiculo, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validated();
        $file = $request->file('file');
        $path = $file->store('vehicles/'.$vehiculo->public_id.'/documents', 'local');
        $document = DB::transaction(function () use ($vehiculo, $data, $file, $path, $request, $audit): VehicleDocument {
            $record = $vehiculo->documents()->create(['uploaded_by' => $this->actor($request)->getKey(), 'type' => $data['document_type'], 'title' => $data['title'], 'expires_at' => $data['expires_at'] ?? null, 'storage_path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize()]);
            $audit->record(AuditEntryData::forSubject($record, $this->actor($request), 'vehicles', 'vehicle_document_uploaded', 'Documento privado del vehículo cargado', ['vehicle_id' => $vehiculo->public_id, 'type' => $record->type, 'title' => $record->title, 'expires_at' => $record->expires_at?->toDateString()], source: 'api'));

            return $record;
        });

        return response()->json(['data' => ['id' => $document->public_id, 'title' => $document->title, 'type' => $document->type, 'expires_at' => $document->expires_at?->toDateString()]], 201);
    }

    public function downloadDocument(VehicleReadRequest $request, Vehicle $vehiculo, VehicleDocument $document): StreamedResponse
    {
        abort_unless($document->vehicle_id === $vehiculo->getKey() && Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download($document->storage_path, $document->original_name);
    }

    private function actor(VehicleCommandRequest $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

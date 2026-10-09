<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\AddDailyWeighingEntryAction;
use App\Actions\Lots\DeleteDailyWeighingEntryAction;
use App\Http\Requests\Lots\AddDailyWeighingEntryRequest;
use App\Http\Requests\Lots\DailyWeighingDistributionRequest;
use App\Http\Requests\Lots\DeleteDailyWeighingEntryRequest;
use App\Http\Requests\Lots\ListDailyWeighingsRequest;
use App\Http\Requests\Lots\ViewDailyWeighingRequest;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\DailyWeighingEntry;
use App\Models\Lots\Flock;
use App\Services\Lots\DailyWeighingPresenter;
use App\Services\Lots\WeighingMath;
use Illuminate\Http\JsonResponse;

final readonly class DailyWeighingController
{
    public function index(ListDailyWeighingsRequest $request, DailyWeighingPresenter $presenter): JsonResponse
    {
        $data = $request->validated();
        $flock = Flock::query()->where('public_id', $data['flock_id'])->firstOrFail();
        $query = DailyWeighing::query()->where('flock_id', $flock->id)->with(['flock', 'lastEntry']);
        if (isset($data['date_from'])) {
            $query->whereDate('local_date', '>=', $data['date_from']);
        }
        if (isset($data['date_to'])) {
            $query->whereDate('local_date', '<=', $data['date_to']);
        }
        $page = $query->orderByDesc('local_date')->orderByDesc('id')->cursorPaginate((int) ($data['per_page'] ?? 20), ['*'], 'cursor', $data['cursor'] ?? null);

        return response()->json(['data' => $page->getCollection()->map(fn (DailyWeighing $daily): array => $presenter->daily($daily))->all(), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    public function show(ViewDailyWeighingRequest $request, DailyWeighing $jornada, DailyWeighingPresenter $presenter): JsonResponse
    {
        return $this->detail($jornada, $request->validated(), $presenter);
    }

    public function showByDate(ViewDailyWeighingRequest $request, Flock $lote, string $fecha, DailyWeighingPresenter $presenter): JsonResponse
    {
        $daily = DailyWeighing::query()->where('flock_id', $lote->id)->whereDate('local_date', $fecha)->firstOrFail();

        return $this->detail($daily, $request->validated(), $presenter);
    }

    public function storeEntry(AddDailyWeighingEntryRequest $request, Flock $lote, AddDailyWeighingEntryAction $action): JsonResponse
    {
        $operation = $action->execute($lote, $request->attributesForAction(), $request->actor());

        return response()->json(['data' => ['operation_id' => $operation->operation_id, ...$operation->result]], 201);
    }

    public function deleteEntry(DeleteDailyWeighingEntryRequest $request, DailyWeighing $jornada, DailyWeighingEntry $ingreso, DeleteDailyWeighingEntryAction $action): JsonResponse
    {
        abort_unless($ingreso->daily_weighing_id === $jornada->id, 404);
        $operation = $action->execute($jornada, $ingreso, $request->attributesForAction(), $request->actor());

        return response()->json(['data' => ['operation_id' => $operation->operation_id, ...$operation->result]]);
    }

    public function distribution(DailyWeighingDistributionRequest $request, DailyWeighing $jornada, WeighingMath $math): JsonResponse
    {
        $weights = $jornada->entries()->where('mode', 'individual')->orderBy('id')->pluck('weight_g')->map(static fn ($value): string => (string) $value)->all();
        $distribution = $math->distribution($weights, 'g');

        return response()->json(['data' => [
            'available' => $distribution['reason'] === null,
            'reason' => $distribution['reason'],
            'unit' => 'g',
            'n' => count($weights),
            'mean' => $distribution['mean'],
            'sample_stddev' => $distribution['stddev'],
            'bins' => $distribution['bins'],
            'curve' => $distribution['curve'],
        ]]);
    }

    /** @param array<string, mixed> $filters */
    private function detail(DailyWeighing $daily, array $filters, DailyWeighingPresenter $presenter): JsonResponse
    {
        $entries = $daily->entries()->orderBy('occurred_at')->orderBy('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 50), ['*'], 'cursor', $filters['cursor'] ?? null);

        return response()->json(['data' => [
            ...$presenter->daily($daily),
            'entries' => $entries->getCollection()->map(fn (DailyWeighingEntry $entry): array => $presenter->entry($entry))->all(),
            'next_cursor' => $entries->nextCursor()?->encode(),
        ]]);
    }
}

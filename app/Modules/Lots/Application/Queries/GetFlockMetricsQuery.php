<?php

namespace App\Modules\Lots\Application\Queries;

use App\Models\Lots\Flock;

final readonly class GetFlockMetricsQuery
{
    public function __construct(private GetEggProductionMetricsQuery $metrics) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(Flock $flock, array $filters): array
    {
        $metrics = $this->metrics->execute([...$filters, 'flock_id' => $flock->public_id]);
        $metrics['lote_id'] = $flock->public_id;

        return $metrics;
    }
}

<?php

namespace App\Modules\ReportingAndAnalytics\Application\Queries;

use App\Models\User;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportResultData;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportQueryNormalizer;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;

final readonly class PreviewReportQuery
{
    public function __construct(
        private ReportSourceRegistry $registry,
        private ReportQueryNormalizer $normalizer,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(string $sourceKey, array $input, User $actor): ReportResultData
    {
        $source = $this->registry->get($sourceKey);
        $this->registry->assertCanRead($actor, $source);

        return $source->preview($this->normalizer->normalize($sourceKey, $input));
    }
}

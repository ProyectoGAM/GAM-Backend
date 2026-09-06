<?php

namespace App\Queries\ReportingAndAnalytics;

use App\DTO\ReportingAndAnalytics\ReportResultData;
use App\Models\User;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;

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

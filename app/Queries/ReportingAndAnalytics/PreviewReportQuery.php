<?php

namespace App\Queries\ReportingAndAnalytics;

use App\DTO\ReportingAndAnalytics\ReportResultData;
use App\Models\User;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
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

        return app(ProductionUnitAccess::class)->within($actor->usesDevicePin() ? $actor->production_unit_id : null,
            fn (): ReportResultData => $source->preview($this->normalizer->normalize($sourceKey, $input)));
    }
}

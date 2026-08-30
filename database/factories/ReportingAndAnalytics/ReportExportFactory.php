<?php

namespace Database\Factories\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportFormat;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReportExport>
 */
final class ReportExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'operation_id' => Str::uuid()->toString(),
            'idempotency_key_hash' => hash('sha256', Str::uuid()->toString()),
            'payload_hash' => hash('sha256', fake()->sentence()),
            'source_key' => 'inventory.stock-balances',
            'definition_version' => '1.0',
            'query' => [],
            'format' => ReportExportFormat::Xlsx,
            'status' => ReportExportStatus::Pending,
            'disk' => 'local',
            'path' => null,
            'file_name' => 'reporte.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => null,
            'expires_at' => now()->addDay(),
            'completed_at' => null,
            'failed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
        ];
    }
}

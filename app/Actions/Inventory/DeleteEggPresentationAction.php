<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Inventory\EggPresentation;
use App\Models\User;
use App\Services\Inventory\EggPresentationCatalog;
use App\ValueObjects\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class DeleteEggPresentationAction
{
    public function __construct(private EggPresentationCatalog $catalog, private AuditRecorder $audit) {}

    public function execute(EggPresentation $presentation, User $actor): void
    {
        if (! $actor->hasRole('admin')) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($presentation, $actor): void {
            $this->catalog->assertEditable();
            $model = EggPresentation::query()->whereKey($presentation->getKey())->lockForUpdate()->firstOrFail();
            $snapshot = [...$model->only(['code', 'name', 'category', 'eggs_per_unit', 'default_unit_price']), 'currency' => Money::CURRENCY];
            $model->delete();
            $this->audit->record(AuditEntryData::forSubject(
                subject: $model,
                actor: $actor,
                logName: 'inventory',
                event: 'egg_presentation_deleted',
                description: 'Presentación de huevos eliminada del catálogo',
                properties: ['subject_snapshot' => $snapshot, 'result' => 'success'],
                attributeChanges: ['old' => ['deleted_at' => null], 'new' => ['deleted_at' => $model->deleted_at->toIso8601String()]],
                source: 'api',
            ));
        }, 3);
    }
}

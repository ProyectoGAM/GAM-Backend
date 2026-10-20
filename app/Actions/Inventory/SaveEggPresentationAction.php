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

final readonly class SaveEggPresentationAction
{
    public function __construct(private EggPresentationCatalog $catalog, private AuditRecorder $audit) {}

    /** @param array{name?:string,eggs_per_unit?:int,default_unit_price?:int} $attributes */
    public function execute(?EggPresentation $presentation, array $attributes, User $actor): EggPresentation
    {
        if (! $actor->hasRole('admin')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($presentation, $attributes, $actor): EggPresentation {
            $this->catalog->assertEditable();
            $creating = $presentation === null;
            $model = $creating ? new EggPresentation : EggPresentation::query()->whereKey($presentation->getKey())->lockForUpdate()->firstOrFail();
            $before = $creating ? [] : $this->snapshot($model);
            $model->fill($attributes)->save();
            $after = $this->snapshot($model);
            if ($before !== $after) {
                $this->audit->record(AuditEntryData::forSubject(
                    subject: $model,
                    actor: $actor,
                    logName: 'inventory',
                    event: $creating ? 'egg_presentation_created' : 'egg_presentation_updated',
                    description: $creating ? 'Presentación de huevos creada' : 'Presentación de huevos actualizada',
                    properties: ['subject_snapshot' => $after, 'result' => 'success'],
                    attributeChanges: ['old' => $before, 'new' => $after],
                    source: 'api',
                ));
            }

            return $model;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(EggPresentation $presentation): array
    {
        return [...$presentation->only(['code', 'name', 'eggs_per_unit', 'default_unit_price']), 'currency' => Money::CURRENCY];
    }
}

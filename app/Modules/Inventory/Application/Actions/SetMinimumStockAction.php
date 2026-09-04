<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\EggStockAccount;
use App\Models\Inventory\StockBalance;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class SetMinimumStockAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(StockBalance $balance, string $minimumQuantity, User $actor): StockBalance
    {
        return DB::transaction(function () use ($balance, $minimumQuantity, $actor): StockBalance {
            $locked = StockBalance::query()->whereKey($balance->getKey())->lockForUpdate()->firstOrFail();
            if (EggStockAccount::query()->where('product_id', $locked->product_id)->where('stock_location_id', $locked->stock_location_id)->exists()) {
                throw new InventoryConflict('Las cuentas técnicas de huevos sólo pueden administrarse desde su módulo especializado.');
            }
            $minimum = BigDecimal::of($minimumQuantity)->toScale(6);
            if ($minimum->isNegative()) {
                throw new InventoryConflict('El stock mínimo no puede ser negativo.');
            }
            $before = (string) $locked->minimum_quantity;
            $locked->forceFill(['minimum_quantity' => (string) $minimum])->save();
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'inventory',
                event: 'stock_minimum_changed',
                description: 'Stock mínimo actualizado',
                properties: ['product_id' => $locked->product_id, 'stock_location_id' => $locked->stock_location_id, 'result' => 'success'],
                attributeChanges: ['old' => ['minimum_quantity' => $before], 'new' => ['minimum_quantity' => (string) $minimum]],
                source: 'api',
                upId: null,
            ));

            return $locked->load(['product', 'stockLocation']);
        });
    }
}

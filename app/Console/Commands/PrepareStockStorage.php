<?php

namespace App\Console\Commands;

use App\Actions\Inventory\PrepareStockStorageAction;
use App\Models\Inventory\StockBalance;
use App\Models\User;
use App\Services\Inventory\StockProductCompatibility;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventario:preparar-almacenamiento {--actor= : Identificador del usuario responsable} {--revisar : Sólo listar saldos incompatibles, sin cambiar datos}')]
#[Description('Clasifica ubicaciones, prepara sanidad y vincula medicamentos sin modificar saldos existentes')]
class PrepareStockStorage extends Command
{
    public function handle(PrepareStockStorageAction $prepare, StockProductCompatibility $compatibility): int
    {
        if (! $this->option('revisar')) {
            $actor = User::query()->findOrFail($this->option('actor'));
            $prepare->execute($actor);
            $this->info('Almacenamiento preparado. Los saldos históricos se conservaron.');
        }
        $incompatible = StockBalance::query()->where('on_hand_quantity', '!=', 0)
            ->whereNotIn('id', $compatibility->compatibleBalances()->select('stock_balances.id'))
            ->orderBy('id')->get(['id', 'product_id', 'stock_location_id', 'on_hand_quantity']);
        $this->table(['Saldo', 'Producto', 'Ubicación', 'Cantidad'], $incompatible->toArray());

        return self::SUCCESS;
    }
}

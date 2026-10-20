<?php

namespace App\Models\Inventory;

use Database\Factories\Inventory\EggPresentationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['code', 'name', 'category', 'eggs_per_unit', 'default_unit_price'])]
final class EggPresentation extends Model
{
    /** @use HasFactory<EggPresentationFactory> */
    use HasFactory;

    protected $attributes = ['category' => 'custom'];

    protected static function booted(): void
    {
        self::creating(function (self $presentation): void {
            $presentation->code ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['eggs_per_unit' => 'integer', 'default_unit_price' => 'integer'];
    }
}

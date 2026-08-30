<?php

namespace App\Models\ReportingAndAnalytics;

use App\Models\User;
use Database\Factories\ReportingAndAnalytics\ReportPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name', 'source_key', 'definition_version', 'configuration'])]
class ReportPreset extends Model
{
    /** @use HasFactory<ReportPresetFactory> */
    use HasFactory;

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'name' => trim($value),
                'normalized_name' => Str::lower(trim($value)),
            ],
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

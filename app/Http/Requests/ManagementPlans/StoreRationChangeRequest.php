<?php

namespace App\Http\Requests\ManagementPlans;

use App\ValueObjects\Inventory\FeedStockGrams;

final class StoreRationChangeRequest extends ManagementExecutionRequest
{
    public function authorize(): bool
    {
        return $this->authorizeFlockExecution();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            ...$this->activityLinkRules(),
            'ration_description' => ['required', 'string', 'min:1', 'max:5000'],
            'feed_recipe_id' => ['required_without:daily_quantity_grams', 'integer', 'min:1', 'max:9223372036854775807'],
            'daily_quantity_grams' => ['required_without:feed_recipe_id', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    FeedStockGrams::parse($value, positive: true, allowNegative: false);
                } catch (\InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        foreach (['responsible_user_id', 'feed_recipe_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }
}

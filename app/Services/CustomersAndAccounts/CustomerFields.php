<?php

namespace App\Services\CustomersAndAccounts;

use App\Services\FarmStructure\UruguayBoundary;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CustomerFields
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'document_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'locality_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:localities,id'],
            'address' => ['sometimes', 'nullable', 'string', 'max:240'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'visit_frequency' => ['sometimes', 'nullable', 'string', 'max:240'],
            'commercial_conditions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'delivery_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function normalize(array $data): array
    {
        foreach ($data as &$value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }
        }

        return $data;
    }

    /** Valida también invocaciones internas; la pareja completa se comprueba después del lock. */
    public static function validate(array $data, UruguayBoundary $boundary): array
    {
        $unknown = array_diff(array_keys($data), array_keys(self::rules()));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => 'El campo no está permitido en esta operación.']);
        }
        $data = Validator::make(self::normalize($data), self::rules())->validate();
        if (($data['document_type'] ?? null) === null xor ($data['document_number'] ?? null) === null) {
            throw ValidationException::withMessages(['document_number' => 'Indicá el tipo y el número de documento, o dejá ambos vacíos.']);
        }
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        if (($latitude === null) !== ($longitude === null)) {
            throw ValidationException::withMessages(['latitude' => 'Indicá ambas coordenadas o dejá ambas vacías.']);
        }
        if ($latitude !== null && ! $boundary->contains((float) $latitude, (float) $longitude)) {
            throw ValidationException::withMessages(['latitude' => 'La ubicación debe encontrarse dentro de Uruguay.']);
        }

        return $data;
    }
}

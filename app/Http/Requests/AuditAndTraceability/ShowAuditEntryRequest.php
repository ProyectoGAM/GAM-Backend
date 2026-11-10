<?php

namespace App\Http\Requests\AuditAndTraceability;

use Illuminate\Foundation\Http\FormRequest;

final class ShowAuditEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('can_view_audit') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}

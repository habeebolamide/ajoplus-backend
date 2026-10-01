<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGroupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contribution_amount_kobo' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'biweekly', 'monthly'])],
            'max_members' => ['required', 'integer', 'min:2', 'max:200'],
            'requires_approval' => ['sometimes', 'boolean'],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}

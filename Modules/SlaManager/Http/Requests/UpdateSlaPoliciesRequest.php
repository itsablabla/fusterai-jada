<?php

namespace Modules\SlaManager\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSlaPoliciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policies' => ['required', 'array', 'size:4'],
            'policies.*.priority' => ['required', 'string', 'in:urgent,high,normal,low'],
            'policies.*.first_response_minutes' => ['required', 'integer', 'min:1'],
            'policies.*.resolution_minutes' => ['required', 'integer', 'min:1'],
            'policies.*.active' => ['boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $validSlugs = array_keys(config('ai.models', []));

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'model_set' => ['sometimes', 'array'],
            'model_set.panelists' => ['sometimes', 'array', 'size:3'],
            'model_set.panelists.*' => ['string', 'in:'.implode(',', $validSlugs)],
            'model_set.referee' => ['sometimes', 'string', 'in:'.implode(',', $validSlugs)],
        ];
    }
}

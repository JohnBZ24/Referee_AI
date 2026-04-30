<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public access
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'model_set' => ['sometimes', 'array'],
            'model_set.panelists' => ['sometimes', 'array', 'min:1', 'max:5'],
            'model_set.panelists.*' => ['string'],
            'referee_model' => ['sometimes', 'string'],
        ];
    }
}

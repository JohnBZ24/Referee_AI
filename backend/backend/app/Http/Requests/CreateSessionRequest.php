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
        $models = (array) config('referee_ai.models', []);
        $validKeys = array_keys($models);
        $validModelIds = [];
        foreach ($models as $cfg) {
            if (! is_array($cfg)) {
                continue;
            }
            $id = trim((string) ($cfg['model_id'] ?? ''));
            if ($id !== '') {
                $validModelIds[] = $id;
            }
        }

        // Support both legacy internal keys and public model_id values.
        $valid = array_values(array_unique(array_filter(array_merge($validKeys, $validModelIds))));

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'model_set' => ['sometimes', 'array'],
            'model_set.panelists' => ['sometimes', 'array', 'size:3'],
            'model_set.panelists.*' => ['string', 'in:'.implode(',', $valid)],
            // Back-compat: older clients sent referee under model_set.referee.
            'model_set.referee' => ['sometimes', 'string', 'in:'.implode(',', $valid)],

            // Preferred: top-level referee_model (matches UpdateSessionRequest and frontend).
            'referee_model' => ['sometimes', 'string', 'in:'.implode(',', $valid)],
        ];
    }
}

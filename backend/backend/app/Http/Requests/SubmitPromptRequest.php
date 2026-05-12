<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1', 'max:10000'],
            'round_id' => ['nullable', 'string', 'max:64'],
            'context_json' => ['nullable', 'string', 'max:20000'],
            'web_search_mode' => ['nullable', 'string', 'in:auto,on,off'],
            'web_search_query' => ['nullable', 'string', 'max:500'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => [
                'file',
                'max:10240', // 10MB
                'mimetypes:application/pdf,image/png,image/jpeg,image/webp,image/gif,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/csv,application/csv',
            ],
        ];
    }
}

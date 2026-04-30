<?php

return [
    'models' => [
        'kimi-1' => [
            'name' => env('OPENROUTER_MODEL_1', 'openai/gpt-oss-20b:free'),
            'provider' => env('KIMI_PROVIDER', 'openrouter'),
            'model_id' => env('OPENROUTER_MODEL_1', 'openai/gpt-oss-20b:free'),
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'kimi-2' => [
            'name' => env('OPENROUTER_MODEL_2', 'google/gemma-3-4b-it:free'),
            'provider' => env('KIMI_PROVIDER', 'openrouter'),
            'model_id' => env('OPENROUTER_MODEL_2', 'google/gemma-3-4b-it:free'),
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'kimi-3' => [
            'name' => env('OPENROUTER_MODEL_3', 'meta-llama/llama-3.2-3b-instruct:free'),
            'provider' => env('KIMI_PROVIDER', 'openrouter'),
            'model_id' => env('OPENROUTER_MODEL_3', 'meta-llama/llama-3.2-3b-instruct:free'),
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'kimi-4' => [
            'name' => env('OPENROUTER_MODEL_4', 'qwen/qwen3-next-80b-a3b-instruct:free').' (ref)',
            'provider' => env('KIMI_PROVIDER', 'openrouter'),
            'model_id' => env('OPENROUTER_MODEL_4', 'qwen/qwen3-next-80b-a3b-instruct:free'),
            'api_key' => env('OPENROUTER_API_KEY'),
        ],

        // ── Extra OpenRouter models (non-free, generally cheap) ─────────────
        // These appear in GET /api/v1/models so the frontend "Change Models"
        // picker can offer more options.
        'meta-llama/llama-3-8b-instruct' => [
            'name' => 'Meta: Llama 3 8B Instruct',
            'provider' => 'openrouter',
            'model_id' => 'meta-llama/llama-3-8b-instruct',
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'mistralai/mistral-7b-instruct-v0.1' => [
            'name' => 'Mistral 7B Instruct v0.1',
            'provider' => 'openrouter',
            'model_id' => 'mistralai/mistral-7b-instruct-v0.1',
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'qwen/qwen-2.5-7b-instruct' => [
            'name' => 'Qwen 2.5 7B Instruct',
            'provider' => 'openrouter',
            'model_id' => 'qwen/qwen-2.5-7b-instruct',
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'mistralai/mixtral-8x7b-instruct' => [
            'name' => 'Mixtral 8x7B Instruct',
            'provider' => 'openrouter',
            'model_id' => 'mistralai/mixtral-8x7b-instruct',
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'deepseek/deepseek-chat' => [
            'name' => 'DeepSeek Chat',
            'provider' => 'openrouter',
            'model_id' => 'deepseek/deepseek-chat',
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'deepseek/deepseek-r1' => [
            'name' => 'DeepSeek R1',
            'provider' => 'openrouter',
            'model_id' => 'deepseek/deepseek-r1',
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'google/gemma-3-12b-it' => [
            'name' => 'Gemma 3 12B IT',
            'provider' => 'openrouter',
            'model_id' => 'google/gemma-3-12b-it',
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
    ],

    'default_panelists' => ['kimi-1', 'kimi-2', 'kimi-3'],

    'default_referee' => 'kimi-4',

    // Model slug used to generate a short session title after the first prompt.
    // Defaults to the referee model.
    'title_model' => env('AI_TITLE_MODEL', null),

    // OpenRouter PDF parser engine used when PDFs are attached.
    // Options per OpenRouter docs: cloudflare-ai (free), mistral-ocr, native
    'openrouter_pdf_engine' => env('OPENROUTER_PDF_ENGINE', 'cloudflare-ai'),

    // Fallback engine used when OpenRouter returns "Failed to parse <file>.pdf".
    // Set to null/empty to disable retry.
    'openrouter_pdf_engine_fallback' => env('OPENROUTER_PDF_ENGINE_FALLBACK', 'mistral-ocr'),

    /** Seconds before an AI request times out */
    'timeout' => (int) env('AI_TIMEOUT', 120),

    'api_keys' => [
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'openai' => env('OPENAI_API_KEY'),
        'google' => env('GOOGLE_AI_API_KEY'),
        'moonshot' => env('MOONSHOT_API_KEY'),
        'openrouter' => env('OPENROUTER_API_KEY'),
    ],
];

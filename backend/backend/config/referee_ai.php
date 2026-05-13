<?php

return [
    'models' => [
        // Default slots driven entirely by env model IDs.
        // Keys are internal only; the API returns model_id as the public identifier.
        'panelist_1' => [
            'name' => env('OPENROUTER_MODEL_1', 'meta-llama/llama-3-8b-instruct'),
            'provider' => env('KIMI_PROVIDER', 'openrouter'),
            'model_id' => env('OPENROUTER_MODEL_1', 'meta-llama/llama-3-8b-instruct'),
        ],
        'panelist_2' => [
            'name' => env('OPENROUTER_MODEL_2', 'qwen/qwen-2.5-7b-instruct'),
            'provider' => env('KIMI_PROVIDER', 'openrouter'),
            'model_id' => env('OPENROUTER_MODEL_2', 'qwen/qwen-2.5-7b-instruct'),
        ],
        'panelist_3' => [
            'name' => env('OPENROUTER_MODEL_3', 'deepseek/deepseek-chat'),
            'provider' => env('KIMI_PROVIDER', 'openrouter'),
            'model_id' => env('OPENROUTER_MODEL_3', 'deepseek/deepseek-chat'),
        ],
        'referee' => [
            'name' => env('OPENROUTER_MODEL_4', 'openai/gpt-4o-mini'),
            'provider' => env('KIMI_PROVIDER', 'openrouter'),
            'model_id' => env('OPENROUTER_MODEL_4', 'openai/gpt-4o-mini'),
        ],

        // Extra OpenRouter models (non-free, generally cheap).
        'meta-llama/llama-3-8b-instruct' => [
            'name' => 'Meta: Llama 3 8B Instruct',
            'provider' => 'openrouter',
            'model_id' => 'meta-llama/llama-3-8b-instruct',
        ],
        'qwen/qwen-2.5-7b-instruct' => [
            'name' => 'Qwen 2.5 7B Instruct',
            'provider' => 'openrouter',
            'model_id' => 'qwen/qwen-2.5-7b-instruct',
        ],
        'openai/gpt-4o-mini' => [
            'name' => 'OpenAI: GPT-4o mini',
            'provider' => 'openrouter',
            'model_id' => 'openai/gpt-4o-mini',
        ],

        'openai/gpt-4.1-mini' => [
            'name' => 'OpenAI: GPT-4.1 mini',
            'provider' => 'openrouter',
            'model_id' => 'openai/gpt-4.1-mini',
        ],

        'openai/gpt-4.1' => [
            'name' => 'OpenAI: GPT-4.1',
            'provider' => 'openrouter',
            'model_id' => 'openai/gpt-4.1',
        ],

        'anthropic/claude-3.5-sonnet' => [
            'name' => 'Anthropic: Claude 3.5 Sonnet',
            'provider' => 'openrouter',
            'model_id' => 'anthropic/claude-3.5-sonnet',
        ],

        'google/gemini-2.0-flash' => [
            'name' => 'Google: Gemini 2.0 Flash',
            'provider' => 'openrouter',
            'model_id' => 'google/gemini-2.0-flash',
        ],

        'google/gemini-2.5-flash' => [
            'name' => 'Google: Gemini 2.5 Flash',
            'provider' => 'openrouter',
            'model_id' => 'google/gemini-2.5-flash',
        ],
        'deepseek/deepseek-chat' => [
            'name' => 'DeepSeek Chat',
            'provider' => 'openrouter',
            'model_id' => 'deepseek/deepseek-chat',
        ],
        'deepseek/deepseek-r1' => [
            'name' => 'DeepSeek R1',
            'provider' => 'openrouter',
            'model_id' => 'deepseek/deepseek-r1',
        ],
        'google/gemma-3-12b-it' => [
            'name' => 'Gemma 3 12B IT',
            'provider' => 'openrouter',
            'model_id' => 'google/gemma-3-12b-it',
        ],
    ],

    // Defaults stored on sessions as model_id values (not internal keys).
    'default_panelists' => [
        env('OPENROUTER_MODEL_1', 'meta-llama/llama-3-8b-instruct'),
        env('OPENROUTER_MODEL_2', 'qwen/qwen-2.5-7b-instruct'),
        env('OPENROUTER_MODEL_3', 'deepseek/deepseek-chat'),
    ],
    'default_referee' => env('OPENROUTER_MODEL_4', 'openai/gpt-4o-mini'),

    'title_model' => env('AI_TITLE_MODEL', null),

    // OpenRouter PDF parser engine used when PDFs are attached.
    'openrouter_pdf_engine' => env('OPENROUTER_PDF_ENGINE', 'cloudflare-ai'),
    'openrouter_pdf_engine_fallback' => env('OPENROUTER_PDF_ENGINE_FALLBACK', 'mistral-ocr'),

    // Attachment text extraction limits (applied when we inline attachment contents into prompts).
    'attachment_max_chars' => (int) env('AI_ATTACHMENT_MAX_CHARS', 30_000),
    'attachment_max_files' => (int) env('AI_ATTACHMENT_MAX_FILES', 3),

    // Conversation context (chat memory) injected into each prompt.
    // Mode: auto|on|off
    'context_default_mode' => env('AI_CONTEXT_DEFAULT_MODE', 'auto'),
    'context_max_messages' => (int) env('AI_CONTEXT_MAX_MESSAGES', 12),
    'context_max_chars' => (int) env('AI_CONTEXT_MAX_CHARS', 12_000),

    // If you use WSL-based extractors (e.g. antiword), optionally pin a distro name.
    // Example: Ubuntu
    'wsl_distro' => env('AI_WSL_DISTRO', null),

    // Web Search
    // Provider: tavily | brave | serper
    'web_search_provider' => env('WEB_SEARCH_PROVIDER', 'tavily'),

    // Tavily Web Search API
    'tavily_api_key' => env('TAVILY_API_KEY', ''),

    // Brave Web Search API
    'brave_search_api_key' => env('BRAVE_SEARCH_API_KEY', ''),

    // Serper (Google Search API)
    'serper_api_key' => env('SERPER_API_KEY', ''),
    'web_search_default_mode' => env('WEB_SEARCH_DEFAULT_MODE', 'auto'), // auto|on|off
    'web_search_max_results' => (int) env('WEB_SEARCH_MAX_RESULTS', 5),

    'timeout' => (int) env('AI_TIMEOUT', 120),
];

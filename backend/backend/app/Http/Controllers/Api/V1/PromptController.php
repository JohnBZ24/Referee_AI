<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPromptRequest;
use App\Models\AiSession;
use App\Models\Message;
use App\Services\AI\AIService;
use App\Services\WebSearch\BraveWebSearchService;
use App\Services\WebSearch\SerperWebSearchService;
use App\Services\WebSearch\TavilyWebSearchService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PromptController extends Controller
{
    private const int MAX_HIDDEN_REFS = 3;

    public function __construct(
        private readonly AIService $aiService,
        private readonly BraveWebSearchService $braveWebSearch,
        private readonly SerperWebSearchService $serperWebSearch,
        private readonly TavilyWebSearchService $tavilyWebSearch,
    ) {}

    public function submit(SubmitPromptRequest $request, AiSession $session): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $session): void {
            $requestId = (string) Str::uuid();
            try {
                // Streaming responses can legitimately exceed PHP's default execution time.
                // Ensure long-running SSE requests don't fatal mid-stream.
                if (function_exists('set_time_limit')) {
                    @set_time_limit((int) config('referee_ai.timeout', 120) + 30);
                }

                $this->disableBuffering();

                $roundId = $request->input('round_id');
                if (! is_string($roundId) || trim($roundId) === '') {
                    $roundId = (string) Str::uuid();
                }

                $prompt = $request->input('prompt');

                // Build the prompt that will be sent to the panelist models.
                //
                // Order matters:
                // 1) Conversation context (same-session memory)
                // 2) Hidden context (cross-session references via @ mentions)
                // 3) User prompt (the visible message)
                // 4) Optional web sources block (when web search is enabled)
                $hiddenContext = $this->buildHiddenContext($request);
                $effectivePrompt = $hiddenContext !== ''
                    ? $hiddenContext."\n\nUser prompt:\n".(string) $prompt
                    : (string) $prompt;

                // Same-session memory is "auto" by default: only include history when the
                // new prompt looks like a follow-up. This avoids forcing every message to
                // behave like a referee that always references prior rounds.
                $contextMode = (string) ($request->input('context_mode') ?: config('referee_ai.context_default_mode', 'auto'));
                $contextMode = strtolower(trim($contextMode));
                if (! in_array($contextMode, ['auto', 'on', 'off'], true)) {
                    $contextMode = 'auto';
                }

                $shouldIncludeContext = $contextMode === 'on'
                    || ($contextMode === 'auto' && $this->shouldAutoIncludeConversationContext((string) $prompt));

                if ($shouldIncludeContext) {
                    $conversationContext = $this->buildConversationContext($session, $roundId);
                    if ($conversationContext !== '') {
                        $effectivePrompt = $conversationContext."\n\n".$effectivePrompt;
                    }
                }

                $webSources = $this->maybeWebSearch($request, (string) $prompt);
                if ($webSources !== []) {
                    $effectivePrompt .= "\n\n".$this->buildWebSourcesBlock($webSources);
                }

                if (config('app.debug') && $webSources === [] && (string) ($request->input('web_search_mode') ?? '') !== 'off') {
                    $provider = strtolower(trim((string) config('referee_ai.web_search_provider', 'tavily')));
                    $hasKey = $provider === 'brave'
                        ? trim((string) config('referee_ai.brave_search_api_key', '')) !== ''
                        : trim((string) config('referee_ai.tavily_api_key', '')) !== '';

                    Log::debug('web_search_skipped_or_empty', [
                        'provider' => $provider,
                        'has_key' => $hasKey,
                        'mode' => (string) ($request->input('web_search_mode') ?: config('referee_ai.web_search_default_mode', 'auto')),
                        'query_preview' => substr((string) $prompt, 0, 160),
                    ]);
                }

                if ($webSources !== []) {
                    $this->sseEmit('web_sources', [
                        'session_id' => $session->id,
                        'round_id' => $roundId,
                        'query' => $webSources['query'],
                        'sources' => $webSources['sources'],
                        'answer' => $webSources['answer'] ?? null,
                    ]);
                }
                $panelists = $session->model_set['panelists'] ?? config('referee_ai.default_panelists');
                $refereeSlug = $session->referee_model ?? config('referee_ai.default_referee');

                /** @var array<int, UploadedFile> $attachments */
                $attachments = $request->file('attachments', []);

                // Persist user message
                Message::create([
                    'session_id' => $session->id,
                    'round_id' => $roundId,
                    'role' => 'user',
                    'content' => $prompt,
                    'status' => 'complete',
                ]);

                // Create one pending message per panelist
                $panelistMessages = [];
                foreach ($panelists as $i => $slug) {
                    $panelistMessages[$i] = Message::create([
                        'session_id' => $session->id,
                        'round_id' => $roundId,
                        'role' => 'panelist',
                        'model_name' => $slug,
                        'panel_position' => $i + 1,
                        'status' => 'streaming',
                    ]);
                }

                /** @var array<int, string> $panelistResponses */
                $panelistResponses = array_fill(0, count($panelists), '');

                $fullResponses = $this->aiService->streamPanelists(
                    $panelists,
                    $effectivePrompt,
                    $attachments,
                    function (int $index, string $chunk) use ($panelistMessages, &$panelistResponses, $roundId): void {
                        $panelistResponses[$index] .= $chunk;

                        $this->sseEmit('panelist_chunk', [
                            'session_id' => $panelistMessages[$index]->session_id,
                            'round_id' => $roundId,
                            'message_id' => $panelistMessages[$index]->id,
                            'position' => $index + 1,
                            'model_name' => $panelistMessages[$index]->model_name,
                            'content' => $chunk,
                            'complete' => false,
                        ]);
                    },
                    function (int $index, int $tokens, string $content) use ($panelistMessages, &$panelistResponses, $roundId): void {
                        $panelistResponses[$index] = $content;

                        $panelistMessages[$index]->update([
                            'content' => $content,
                            'status' => 'complete',
                            'tokens_used' => $tokens ?: null,
                        ]);

                        $this->sseEmit('panelist_complete', [
                            'session_id' => $panelistMessages[$index]->session_id,
                            'round_id' => $roundId,
                            'message_id' => $panelistMessages[$index]->id,
                            'position' => $index + 1,
                            'model_name' => $panelistMessages[$index]->model_name,
                            'tokens' => $tokens,
                        ]);
                    },
                    function (int $index, int $httpCode, string $error) use ($panelistMessages, &$panelistResponses, $roundId, $requestId): void {
                        $model = (string) ($panelistMessages[$index]->model_name ?? '');
                        Log::warning('panelist_error', [
                            'request_id' => $requestId,
                            'session_id' => $panelistMessages[$index]->session_id,
                            'round_id' => $roundId,
                            'message_id' => $panelistMessages[$index]->id,
                            'model_name' => $model,
                            'http_code' => $httpCode,
                            'error' => $error,
                        ]);

                        $public = match (true) {
                            $httpCode === 429 => "That model is rate-limited right now. Try again in a moment. (id: {$requestId})",
                            $httpCode === 404 => "That model is temporarily unavailable. Try a different model. (id: {$requestId})",
                            default => "That model failed to respond. Please try again. (id: {$requestId})",
                        };

                        $panelistMessages[$index]->update([
                            'content' => $public,
                            'status' => 'complete',
                            'tokens_used' => null,
                        ]);

                        $panelistResponses[$index] = $public;

                        $this->sseEmit('panelist_error', [
                            'session_id' => $panelistMessages[$index]->session_id,
                            'round_id' => $roundId,
                            'message_id' => $panelistMessages[$index]->id,
                            'position' => $index + 1,
                            'model_name' => $panelistMessages[$index]->model_name,
                            'request_id' => $requestId,
                            'user_message' => $public,
                        ]);
                    },
                );

                // Use what we actually streamed (including errors) for the referee.
                $fullResponses = $panelistResponses;

                // Referee pass
                //
                // The referee model does NOT stream in parallel workers; we stream it directly
                // using Laravel AI and emit SSE deltas to the client.
                $refereeMessage = Message::create([
                    'session_id' => $session->id,
                    'round_id' => $roundId,
                    'role' => 'referee',
                    'model_name' => $refereeSlug,
                    'status' => 'streaming',
                ]);

                $this->sseEmit('referee_start', ['session_id' => $session->id, 'round_id' => $roundId, 'message_id' => $refereeMessage->id, 'request_id' => $requestId]);

                $refereePrompt = $this->buildRefereePrompt($prompt, $panelists, $fullResponses);
                if ($hiddenContext !== '') {
                    $refereePrompt = $hiddenContext."\n\n".$refereePrompt;
                }

                try {
                    $refereeResponse = $this->aiService->streamSingle(
                        $refereeSlug,
                        $refereePrompt,
                        $attachments,
                        function (string $chunk) use ($refereeMessage, $roundId): void {
                            $this->sseEmit('referee_chunk', [
                                'session_id' => $refereeMessage->session_id,
                                'round_id' => $roundId,
                                'message_id' => $refereeMessage->id,
                                'content' => $chunk,
                            ]);
                        },
                    );
                } catch (\Throwable $e) {
                    Log::error('referee_error', [
                        'request_id' => $requestId,
                        'session_id' => $refereeMessage->session_id,
                        'round_id' => $roundId,
                        'message_id' => $refereeMessage->id,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);

                    $httpCode = 500;
                    $msg = $e->getMessage();

                    if ($e instanceof RateLimitedException) {
                        $httpCode = 429;
                    } elseif ($e instanceof RequestException) {
                        $httpCode = $e->response ? (int) $e->response->status() : 500;
                    } elseif (preg_match('/status code\s+(\d{3})/i', $msg, $m) === 1) {
                        $httpCode = (int) $m[1];
                    }

                    $content = match (true) {
                        $httpCode === 429 => "Referee is rate-limited right now. Try again in a moment. (id: {$requestId})",
                        $httpCode === 404 => "Referee model is temporarily unavailable. Try a different referee model. (id: {$requestId})",
                        default => "Referee failed to respond. Please try again. (id: {$requestId})",
                    };

                    $refereeMessage->update([
                        'content' => $content,
                        'status' => 'complete',
                    ]);

                    $this->sseEmit('referee_complete', [
                        'session_id' => $refereeMessage->session_id,
                        'round_id' => $roundId,
                        'message_id' => $refereeMessage->id,
                        'winner' => null,
                        'summary' => $content,
                        'request_id' => $requestId,
                    ]);

                    $this->sseEmit('done', ['session_id' => $session->id, 'round_id' => $roundId, 'request_id' => $requestId]);

                    return;
                }

                ['winner' => $winner, 'summary' => $summary] = $this->parseRefereeResponse($refereeResponse, $panelists);

                $refereeMessage->update([
                    'content' => $refereeResponse,
                    'status' => 'complete',
                ]);

                $this->sseEmit('referee_complete', [
                    'session_id' => $refereeMessage->session_id,
                    'round_id' => $roundId,
                    'message_id' => $refereeMessage->id,
                    'winner' => $winner,
                    'summary' => $summary,
                    'request_id' => $requestId,
                ]);

                // Auto-title the session from the first user prompt
                if ($session->messages()->where('role', 'user')->count() === 1) {
                    $session->update([
                        'title' => $this->generateSessionTitle($prompt, $refereeSlug),
                    ]);
                }

                $this->sseEmit('done', ['session_id' => $session->id, 'round_id' => $roundId, 'request_id' => $requestId]);
            } catch (\Throwable $e) {
                // Never let exceptions bubble after output has begun (it breaks SSE).
                $this->disableBuffering();
                $fallbackRoundId = $request->input('round_id');
                if (! is_string($fallbackRoundId) || trim($fallbackRoundId) === '') {
                    $fallbackRoundId = (string) Str::uuid();
                }
                Log::error('prompt_stream_fatal', [
                    'request_id' => $requestId,
                    'session_id' => $session->id,
                    'round_id' => $fallbackRoundId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                $this->sseEmit('referee_complete', [
                    'session_id' => $session->id,
                    'round_id' => $fallbackRoundId,
                    'message_id' => null,
                    'winner' => null,
                    'summary' => "Something went wrong. Please try again. (id: {$requestId})",
                    'request_id' => $requestId,
                ]);
                $this->sseEmit('done', ['session_id' => $session->id, 'round_id' => $fallbackRoundId, 'request_id' => $requestId]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function sseEmit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
        echo 'data: '.($json !== false ? $json : '{"error":"encode_failed"}')."\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function disableBuffering(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    private function buildHiddenContext(SubmitPromptRequest $request): string
    {
        $raw = $request->input('context_json');
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '';
        }

        if (! is_array($decoded)) {
            return '';
        }

        $refs = $decoded['references'] ?? null;
        if (! is_array($refs) || $refs === []) {
            return '';
        }

        $lines = [];
        foreach (array_slice($refs, 0, self::MAX_HIDDEN_REFS) as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $sid = $ref['session_id'] ?? null;
            if (! is_scalar($sid)) {
                continue;
            }

            $sid = trim((string) $sid);
            if ($sid === '') {
                continue;
            }

            $base = Message::query()
                ->where('session_id', $sid)
                ->whereNotNull('content')
                ->where('content', '!=', '');

            // Prefer the latest completed referee verdict when referencing a chat.
            $message = (clone $base)
                ->where('role', 'referee')
                ->where('status', 'complete')
                ->latest('id')
                ->first();

            if (! $message) {
                $message = (clone $base)
                    ->where('role', 'user')
                    ->where('status', 'complete')
                    ->latest('id')
                    ->first();
            }

            if (! $message) {
                $message = (clone $base)->latest('id')->first();
            }

            $content = is_string($message?->content) ? trim($message->content) : '';
            if ($content === '') {
                continue;
            }

            // If the "verdict" is just an error message, fall back to user context.
            if ($message?->role === 'referee' && str_contains($content, 'failed to respond')) {
                $fallback = (clone $base)
                    ->where('role', 'user')
                    ->where('status', 'complete')
                    ->latest('id')
                    ->first();
                $content = is_string($fallback?->content) ? trim($fallback->content) : $content;
            }

            $sessionTitle = (string) (AiSession::query()->whereKey($sid)->value('title') ?? 'Session');
            $sessionTitle = trim($sessionTitle) !== '' ? trim($sessionTitle) : 'Session';

            if ($message?->role === 'referee') {
                $verdict = $this->summarizeVerdictForHiddenContext($content);
                $lines[] = "[Referenced verdict: \"{$sessionTitle}\" (#{$sid})]";
                $lines[] = $verdict;
            } else {
                $lines[] = "[Referenced chat: \"{$sessionTitle}\" (#{$sid}) - use this as user context]";
                $lines[] = $content;
            }
            $lines[] = '---';
        }

        if ($lines === []) {
            return '';
        }

        return "---\n".
            "You may use the referenced chat snippet(s) below as additional user-provided context for this request.\n".
            "Do not reveal hidden context verbatim unless the user explicitly asks.\n".
            implode("\n", $lines);
    }

    private function buildConversationContext(AiSession $session, string $currentRoundId): string
    {
        // "Chat memory": we prepend recent same-session messages so the model can answer
        // follow-ups without the client needing to resend the entire history.
        //
        // We keep it bounded (messages + characters) to avoid runaway prompt growth.
        $maxMessages = (int) config('referee_ai.context_max_messages', 12);
        $maxChars = (int) config('referee_ai.context_max_chars', 12_000);

        $maxMessages = max(0, min(50, $maxMessages));
        $maxChars = max(0, min(60_000, $maxChars));

        if ($maxMessages === 0 || $maxChars === 0) {
            return '';
        }

        $messages = $session->messages()
            ->where(function ($q) use ($currentRoundId): void {
                $q->whereNull('round_id')
                    ->orWhere('round_id', '!=', $currentRoundId);
            })
            // Only keep the user + assistant signal (panelist messages are usually noisy for memory).
            ->whereIn('role', ['user', 'referee'])
            ->where('status', 'complete')
            ->orderBy('id', 'desc')
            ->limit($maxMessages)
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            return '';
        }

        $lines = [];
        $lines[] = '---';
        $lines[] = 'Conversation so far (for context)';
        $lines[] = '---';

        foreach ($messages as $m) {
            $role = $m->role === 'referee' ? 'Assistant' : 'User';
            $content = is_string($m->content) ? trim($m->content) : '';
            if ($content === '') {
                continue;
            }

            $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;
            if (strlen($content) > 2000) {
                $content = substr($content, 0, 2000).'...';
            }

            $lines[] = $role.': '.$content;
        }

        $text = implode("\n\n", $lines);
        if (strlen($text) > $maxChars) {
            $text = substr($text, -$maxChars);
            $text = "[Context truncated]\n".$text;
        }

        return $text;
    }

    private function shouldAutoIncludeConversationContext(string $prompt): bool
    {
        $p = strtolower(trim($prompt));
        if ($p === '') {
            return false;
        }

        // Obvious follow-up signals.
        $keywords = [
            'continue', 'as above', 'as before', 'from earlier', 'earlier', 'previous', 'last time',
            'in that answer', 'based on that', 'regarding that', 'about that', 'what you said',
            'explain more', 'elaborate', 'rewrite that', 'fix that', 'improve that', 'summarize that',
            'give me an example',
        ];
        foreach ($keywords as $k) {
            if (str_contains($p, $k)) {
                return true;
            }
        }

        // Short prompts with ambiguous references usually need context.
        if (strlen($p) <= 40 && preg_match('/\b(it|that|this|they|them|those|he|she|him|her|there|here)\b/i', $prompt) === 1) {
            return true;
        }

        // Questions that explicitly refer to a prior message.
        if (preg_match('/\b(above|earlier|previous|last)\b/i', $prompt) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array{query: string, sources: array<int, array{title: string, url: string, snippet: string}>, answer?: string}|array{}
     */
    private function maybeWebSearch(SubmitPromptRequest $request, string $prompt): array
    {
        $mode = (string) ($request->input('web_search_mode') ?: config('referee_ai.web_search_default_mode', 'auto'));
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['auto', 'on', 'off'], true)) {
            $mode = 'auto';
        }

        if ($mode === 'off') {
            return [];
        }

        if ($mode === 'auto' && ! $this->shouldAutoWebSearch($prompt)) {
            return [];
        }

        $query = trim((string) ($request->input('web_search_query') ?: $prompt));
        if ($query === '') {
            return [];
        }

        $max = (int) config('referee_ai.web_search_max_results', 5);
        $provider = strtolower(trim((string) config('referee_ai.web_search_provider', 'tavily')));
        if ($provider === 'brave') {
            $sources = $this->braveWebSearch->search($query, $max);
            if ($sources === []) {
                return [];
            }

            return ['query' => $query, 'sources' => $sources];
        }

        if ($provider === 'serper') {
            $sources = $this->serperWebSearch->search($query, $max);
            if ($sources === []) {
                return [];
            }

            return ['query' => $query, 'sources' => $sources];
        }

        $tavily = $this->tavilyWebSearch->search($query, $max);
        $sources = $tavily['results'] ?? [];
        $answer = trim((string) ($tavily['answer'] ?? ''));
        if (! is_array($sources) || $sources === []) {
            return [];
        }

        $out = ['query' => $query, 'sources' => $sources];
        if ($answer !== '') {
            $out['answer'] = $answer;
        }

        return $out;
    }

    private function shouldAutoWebSearch(string $prompt): bool
    {
        $p = strtolower($prompt);
        if ($p === '') {
            return false;
        }

        // "Current" / time-sensitive intent.
        $keywords = [
            'today', 'right now', 'latest', 'recent', 'current', 'this week', 'this month', 'breaking',
            'price', 'cost', 'how much', 'near me', 'open now', 'gas price', 'weather', 'stock',
            'release', 'version', 'updated', 'as of',
        ];

        foreach ($keywords as $k) {
            if (str_contains($p, $k)) {
                return true;
            }
        }

        // Explicit request patterns.
        if (preg_match('/\b(search|look\s*up|find)\b/i', $prompt) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{query: string, sources: array<int, array{title: string, url: string, snippet: string}>}  $web
     */
    private function buildWebSourcesBlock(array $web): string
    {
        $lines = [];
        $lines[] = '---';
        $provider = strtolower(trim((string) config('referee_ai.web_search_provider', 'tavily')));
        $label = match ($provider) {
            'brave' => 'Brave Search',
            'serper' => 'Serper',
            default => 'Tavily',
        };
        $lines[] = "Web sources ({$label})";
        $lines[] = 'Query: '.$web['query'];
        if (isset($web['answer']) && is_string($web['answer']) && trim($web['answer']) !== '') {
            $lines[] = 'Tavily extracted answer: '.trim($web['answer']);
        }
        $lines[] = 'Use these sources for factual claims. Cite sources like [1], [2]. If not supported by sources, say you are not sure.';

        foreach ($web['sources'] as $i => $s) {
            $n = $i + 1;
            $lines[] = "[{$n}] {$s['title']}";
            $lines[] = $s['url'];
            if (trim($s['snippet']) !== '') {
                $lines[] = $s['snippet'];
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string>  $panelists
     * @param  array<int, string>  $responses
     */
    private function buildRefereePrompt(string $userPrompt, array $panelists, array $responses): string
    {
        $modelNames = array_map(
            fn (string $id) => $this->aiService->labelFor($id),
            $panelists,
        );

        $parts = ["You are an expert evaluator comparing AI responses.\n\nOriginal user question:\n{$userPrompt}"];

        foreach ($panelists as $i => $id) {
            $name = $modelNames[$i];
            $response = $responses[$i] ?? '(no response)';
            $parts[] = "Response from {$name}:\n{$response}";
        }

        $parts[] = <<<'INSTRUCTIONS'
Analyze each response for accuracy, clarity, completeness, and helpfulness.
Declare a winner and explain your reasoning in 2-3 paragraphs.
Format:
Winner: [model name]
Reasoning: [your analysis]
INSTRUCTIONS;

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<string>  $panelists
     * @return array{winner: string|null, summary: string}
     */
    private function parseRefereeResponse(string $response, array $panelists): array
    {
        $winner = null;

        if (preg_match('/Winner:\s*(.+)/i', $response, $matches)) {
            $declared = trim($matches[1]);

            // Try to match against a known model name
            foreach ($panelists as $id) {
                $name = $this->aiService->labelFor($id);
                if (stripos($declared, $name) !== false || stripos($declared, $id) !== false) {
                    $winner = $id;
                    break;
                }
            }

            $winner ??= $declared;
        }

        $summary = preg_replace('/Winner:\s*.+\n?/i', '', $response);
        $summary = trim((string) $summary);

        return ['winner' => $winner, 'summary' => $summary];
    }

    private function summarizeVerdictForHiddenContext(string $verdict): string
    {
        $text = trim($verdict);
        if ($text === '') {
            return '';
        }

        $winner = null;
        if (preg_match('/Winner:\s*(.+)/i', $text, $m)) {
            $winner = trim($m[1]);
        }

        $summary = preg_replace('/Winner:\s*.+\n?/i', '', $text);
        $summary = trim((string) $summary);

        // Keep references bounded to avoid polluting the next prompt.
        $summaryCap = 1200;
        $fullCap = 4500;

        if (strlen($summary) > $summaryCap) {
            $summary = substr($summary, 0, $summaryCap).'...';
        }

        $full = $text;
        if (strlen($full) > $fullCap) {
            $full = substr($full, 0, $fullCap).'...';
        }

        $header = is_string($winner) && $winner !== ''
            ? "Winner: {$winner}\nSummary: {$summary}"
            : "Summary: {$summary}";

        return $header."\n\nFull referee verdict:\n".$full;
    }

    private function generateSessionTitle(string $userPrompt, string $fallbackModelSlug): string
    {
        $fallback = trim(mb_substr($userPrompt, 0, 80));
        if ($fallback === '') {
            return 'New Session';
        }

        $modelSlug = (string) (config('referee_ai.title_model') ?: $fallbackModelSlug);

        // Use JSON format for reliable parsing - models are better at following JSON structure
        $titlePrompt = <<<PROMPT
Create a short title for this conversation.

STRICT REQUIREMENTS:
- Respond ONLY with valid JSON in this exact format: {"title": "Your Title Here"}
- The title value must be 3 to 8 words
- No quotes inside the title
- No prefixes, labels, or explanations outside the JSON
- Do not use these words: prompt, question, chat, session, title

User message:
{$userPrompt}
PROMPT;

        try {
            $raw = $this->aiService->complete($modelSlug, $titlePrompt);

            $title = $this->extractTitleFromResponse($raw);

            return $title ?: $fallback;
        } catch (\Throwable $e) {
            error_log('[Title Generation Error] '.$e->getMessage());

            return $fallback;
        }
    }

    private function extractTitleFromResponse(string $raw): ?string
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return null;
        }

        // Try JSON first (models sometimes include extra text around it)
        $jsonCandidate = null;
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $jsonCandidate = $m[0];
        }

        foreach (array_filter([$jsonCandidate, $text]) as $candidate) {
            $decoded = json_decode((string) $candidate, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                continue;
            }

            if (isset($decoded['title']) && is_string($decoded['title'])) {
                $title = $this->normalizeTitle($decoded['title']);

                return $title ?: null;
            }
        }

        // Fallback: try to find anything that looks like a title
        // Remove code blocks
        $text = preg_replace('/```[\s\S]*?```/', '', $text) ?: $text;
        $text = preg_replace('/`[^`]*`/', '', $text) ?: $text;

        // Take first non-empty line
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '{' || $line === '}') {
                continue;
            }
            $title = $this->normalizeTitle($line);
            if ($title) {
                return $title;
            }
        }

        return null;
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        $title = trim($title, "\"'` ");
        $title = preg_replace('/[\r\n]+/', ' ', $title) ?: '';
        $title = trim($title);

        if ($title === '') {
            return '';
        }

        // Strip common wrappers like {"..."} or { ... }
        $title = preg_replace('/^[\s\{\[]+/', '', $title) ?: '';
        $title = preg_replace('/[\s\}\]]+$/', '', $title) ?: '';
        $title = trim($title, "\"'` ");
        $title = trim($title);

        // AGGRESSIVE: Remove ANY word followed by colon at the start (case-insensitive)
        // This catches: "Title:", "TITLE:", "Session Title:", "AI says:", etc.
        while (preg_match('/^[^a-zA-Z0-9]*[a-zA-Z]+\s*:\s*/i', $title)) {
            $title = preg_replace('/^[^a-zA-Z0-9]*[a-zA-Z]+\s*:\s*/i', '', $title) ?: '';
        }
        $title = trim($title);

        // Remove explicit prefix words (case-insensitive)
        $prefixes = [
            'title', 'session', 'need', 'generated', 'suggested', 'proposed',
            'here is', 'the title is', 'this is', 'final', 'optimal',
            'recommended', 'best', 'perfect', 'ideal', 'suitable',
        ];
        $pattern = '/^('.implode('|', $prefixes).')\s*:?\s*/i';
        $title = preg_replace($pattern, '', $title) ?: '';
        $title = trim($title);

        // Remove trailing punctuation
        $title = rtrim($title, '. ,;:!?-–—');
        $title = trim($title);

        // Remove stray braces/quotes that sometimes leak through
        $title = str_replace(['{', '}', '"'], '', $title);
        $title = trim($title);

        // Remove disallowed words
        $title = preg_replace('/\b(prompt|question|chat|session|title)\b/i', '', $title) ?: '';
        $title = trim(preg_replace('/\s{2,}/', ' ', $title) ?: '');

        // Extract words
        $words = preg_split('/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) === 0) {
            return '';
        }

        // Enforce 3-8 words
        if (count($words) > 8) {
            $words = array_slice($words, 0, 8);
        }

        if (count($words) < 3) {
            return '';
        }

        $result = implode(' ', $words);
        $result = rtrim($result, '. ,;:!?-–—');

        return mb_substr($result, 0, 80);
    }
}

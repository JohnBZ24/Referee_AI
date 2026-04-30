<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPromptRequest;
use App\Models\AiSession;
use App\Models\Message;
use App\Services\AI\AIService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PromptController extends Controller
{
    private const int INLINE_TEXT_ATTACHMENT_MAX_BYTES = 204800; // 200KB

    public function __construct(private readonly AIService $aiService) {}

    public function submit(SubmitPromptRequest $request, AiSession $session): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $session): void {
            try {
                // Streaming responses can legitimately exceed PHP's default execution time.
                // Ensure long-running SSE requests don't fatal mid-stream.
                if (function_exists('set_time_limit')) {
                    @set_time_limit((int) config('ai.timeout', 120) + 30);
                }

                $this->disableBuffering();

                $prompt = $request->input('prompt');
                $roundId = $request->input('round_id');
                if (! is_string($roundId) || trim($roundId) === '') {
                    $roundId = (string) Str::uuid();
                }
                $panelists = $session->model_set['panelists'] ?? config('ai.default_panelists');
                $refereeSlug = $session->referee_model ?? config('ai.default_referee');

                /** @var array<int, UploadedFile> $attachments */
                $attachments = $request->file('attachments', []);
                $hasAttachments = is_array($attachments) && count($attachments) > 0;

                $contentParts = null;
                $plugins = null;
                if ($hasAttachments) {
                    [$contentParts, $plugins] = $this->buildOpenRouterContentParts($prompt, $attachments);
                }

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

                // Stream all panelists concurrently (curl_multi under the hood)
                $fullResponses = $hasAttachments
                    ? $this->aiService->streamParallelMessages(
                        $panelists,
                        [[
                            'role' => 'user',
                            'content' => $contentParts,
                        ]],
                        $plugins,
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
                        function (int $index, int $httpCode, string $error) use ($panelistMessages, &$panelistResponses, $roundId): void {
                            $content = "[Error] HTTP {$httpCode}".($error !== '' ? ": {$error}" : '');

                            $panelistMessages[$index]->update([
                                'content' => $content,
                                'status' => 'complete',
                                'tokens_used' => null,
                            ]);

                            $panelistResponses[$index] = $content;

                            $this->sseEmit('panelist_error', [
                                'session_id' => $panelistMessages[$index]->session_id,
                                'round_id' => $roundId,
                                'message_id' => $panelistMessages[$index]->id,
                                'position' => $index + 1,
                                'model_name' => $panelistMessages[$index]->model_name,
                                'http_code' => $httpCode,
                                'error' => $error,
                            ]);
                        },
                    )
                    : $this->aiService->streamParallel(
                        $panelists,
                        $prompt,
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
                        function (int $index, int $httpCode, string $error) use ($panelistMessages, &$panelistResponses, $roundId): void {
                            $content = "[Error] HTTP {$httpCode}".($error !== '' ? ": {$error}" : '');

                            $panelistMessages[$index]->update([
                                'content' => $content,
                                'status' => 'complete',
                                'tokens_used' => null,
                            ]);

                            $panelistResponses[$index] = $content;

                            $this->sseEmit('panelist_error', [
                                'session_id' => $panelistMessages[$index]->session_id,
                                'round_id' => $roundId,
                                'message_id' => $panelistMessages[$index]->id,
                                'position' => $index + 1,
                                'model_name' => $panelistMessages[$index]->model_name,
                                'http_code' => $httpCode,
                                'error' => $error,
                            ]);
                        },
                    );

                // Use what we actually streamed (including errors) for the referee.
                $fullResponses = $panelistResponses;

                // Referee pass
                $refereeMessage = Message::create([
                    'session_id' => $session->id,
                    'round_id' => $roundId,
                    'role' => 'referee',
                    'model_name' => $refereeSlug,
                    'status' => 'streaming',
                ]);

                $this->sseEmit('referee_start', ['session_id' => $session->id, 'round_id' => $roundId, 'message_id' => $refereeMessage->id]);

                $refereePrompt = $this->buildRefereePrompt($prompt, $panelists, $fullResponses);

                try {
                    $refereeResponse = $hasAttachments
                        ? $this->aiService->streamSingleMessages(
                            $refereeSlug,
                            [[
                                'role' => 'user',
                                'content' => array_merge(
                                    $contentParts,
                                    [['type' => 'text', 'text' => "\n\n---\n\n{$refereePrompt}"]],
                                ),
                            ]],
                            $plugins,
                            function (string $chunk) use ($refereeMessage, $roundId): void {
                                $this->sseEmit('referee_chunk', [
                                    'session_id' => $refereeMessage->session_id,
                                    'round_id' => $roundId,
                                    'message_id' => $refereeMessage->id,
                                    'content' => $chunk,
                                ]);
                            },
                        )
                        : $this->aiService->streamSingle(
                            $refereeSlug,
                            $refereePrompt,
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
                    $content = '[Error] '.$e->getMessage();

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
                    ]);

                    $this->sseEmit('done', ['session_id' => $session->id, 'round_id' => $roundId]);

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
                ]);

                // Auto-title the session from the first user prompt
                if ($session->messages()->where('role', 'user')->count() === 1) {
                    $session->update([
                        'title' => $this->generateSessionTitle($prompt, $refereeSlug),
                    ]);
                }

                $this->sseEmit('done', ['session_id' => $session->id, 'round_id' => $roundId]);
            } catch (\Throwable $e) {
                // Never let exceptions bubble after output has begun (it breaks SSE).
                $this->disableBuffering();
                $fallbackRoundId = $request->input('round_id');
                if (! is_string($fallbackRoundId) || trim($fallbackRoundId) === '') {
                    $fallbackRoundId = (string) Str::uuid();
                }
                $this->sseEmit('referee_complete', [
                    'session_id' => $session->id,
                    'round_id' => $fallbackRoundId,
                    'message_id' => null,
                    'winner' => null,
                    'summary' => '[Error] '.$e->getMessage(),
                ]);
                $this->sseEmit('done', ['session_id' => $session->id, 'round_id' => $fallbackRoundId]);
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
        echo 'data: '.json_encode($data)."\n\n";
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

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>|null}
     */
    private function buildOpenRouterContentParts(string $prompt, array $files): array
    {
        $parts = [
            ['type' => 'text', 'text' => $prompt],
        ];

        $hasPdf = false;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
            $name = (string) ($file->getClientOriginalName() ?: 'attachment');

            if (str_starts_with($mime, 'image/')) {
                $dataUrl = $this->fileToDataUrl($file, $mime);
                if ($dataUrl !== '') {
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $dataUrl,
                        ],
                    ];
                }

                continue;
            }

            if (in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true)) {
                $parts[] = [
                    'type' => 'text',
                    'text' => $this->readTextAttachmentInline($file, $name),
                ];

                continue;
            }

            if ($mime === 'application/pdf') {
                $hasPdf = true;
            }

            $dataUrl = $this->fileToDataUrl($file, $mime);
            if ($dataUrl === '') {
                continue;
            }

            $parts[] = [
                'type' => 'file',
                'file' => [
                    'filename' => $name,
                    'file_data' => $dataUrl,
                ],
            ];
        }

        $plugins = null;
        if ($hasPdf) {
            $plugins = [[
                'id' => 'file-parser',
                'pdf' => [
                    'engine' => (string) config('ai.openrouter_pdf_engine', 'cloudflare-ai'),
                ],
            ]];
        }

        return [$parts, $plugins];
    }

    private function fileToDataUrl(UploadedFile $file, string $mime): string
    {
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            return '';
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function readTextAttachmentInline(UploadedFile $file, string $name): string
    {
        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes)) {
            return "Attachment: {$name}\n\n```text\n(unreadable)\n```";
        }

        $truncated = false;
        if (strlen($bytes) > self::INLINE_TEXT_ATTACHMENT_MAX_BYTES) {
            $bytes = substr($bytes, 0, self::INLINE_TEXT_ATTACHMENT_MAX_BYTES);
            $truncated = true;
        }

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        }

        $label = $truncated ? '(truncated to 200KB)' : '';

        return "Attachment: {$name} {$label}\n\n```text\n{$bytes}\n```";
    }

    /**
     * @param  array<string>  $panelists
     * @param  array<int, string>  $responses
     */
    private function buildRefereePrompt(string $userPrompt, array $panelists, array $responses): string
    {
        $modelNames = array_map(
            fn (string $slug) => config("ai.models.{$slug}.name", $slug),
            $panelists,
        );

        $parts = ["You are an expert evaluator comparing AI responses.\n\nOriginal user question:\n{$userPrompt}"];

        foreach ($panelists as $i => $slug) {
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
            foreach ($panelists as $slug) {
                $name = config("ai.models.{$slug}.name", $slug);
                if (stripos($declared, $name) !== false || stripos($declared, $slug) !== false) {
                    $winner = $slug;
                    break;
                }
            }

            $winner ??= $declared;
        }

        $summary = preg_replace('/Winner:\s*.+\n?/i', '', $response);
        $summary = trim((string) $summary);

        return ['winner' => $winner, 'summary' => $summary];
    }

    private function generateSessionTitle(string $userPrompt, string $fallbackModelSlug): string
    {
        $fallback = trim(mb_substr($userPrompt, 0, 80));
        if ($fallback === '') {
            return 'New Session';
        }

        $modelSlug = (string) (config('ai.title_model') ?: $fallbackModelSlug);

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
            $raw = $this->aiService->streamSingle($modelSlug, $titlePrompt, function (): void {
                // no streaming to client for titles
            });

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

        // Try to parse JSON first
        if (preg_match('/\{\s*"title"\s*:\s*"([^"]+)"\s*\}/', $text, $matches)) {
            $title = $this->normalizeTitle($matches[1]);

            return $title ?: null;
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

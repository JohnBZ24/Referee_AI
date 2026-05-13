<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

use function Laravel\Ai\agent;

class AiStreamCommand extends Command
{
    protected $signature = 'ai:stream {provider} {model} {promptFile} {attachmentsFile}';

    protected $description = 'Internal: stream a single model to stdout as JSON lines';

    public function handle(): int
    {
        // Internal worker used by AIService to stream one model in a subprocess.
        //
        // It reads a prompt + attachments from disk and prints JSON lines to stdout:
        // - {type: delta, delta: "..."}
        // - {type: end, tokens: <int>}
        // - {type: error, http_code: <int>, detail: "..."}
        //
        // The parent process multiplexes these lines into SSE events for the browser.

        $provider = (string) $this->argument('provider');
        $model = (string) $this->argument('model');
        $promptFile = (string) $this->argument('promptFile');
        $attachmentsFile = (string) $this->argument('attachmentsFile');

        $prompt = is_file($promptFile) ? (string) file_get_contents($promptFile) : '';

        $attachmentsRaw = is_file($attachmentsFile) ? (string) file_get_contents($attachmentsFile) : '[]';
        $attachmentsDecoded = json_decode($attachmentsRaw, true);

        $attachments = [];
        if (is_array($attachmentsDecoded)) {
            foreach ($attachmentsDecoded as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $path = (string) ($item['path'] ?? '');
                $mime = (string) ($item['mime'] ?? '');
                $kind = (string) ($item['kind'] ?? '');

                if ($path === '' || ! is_file($path)) {
                    continue;
                }

                $isImage = $kind === 'image' || str_starts_with($mime, 'image/');

                $attachments[] = $isImage
                    ? Files\Image::fromPath($path)
                    : Files\Document::fromPath($path);
            }
        }

        $attempts = 0;
        $maxAttempts = 3;

        while (true) {
            $events = [];
            try {
                $stream = agent()->stream(
                    $prompt,
                    provider: $provider,
                    model: $model,
                    attachments: $attachments,
                );

                foreach ($stream as $event) {
                    $events[] = $event;
                    if ($event instanceof TextDelta) {
                        $line = json_encode(['type' => 'delta', 'delta' => $event->delta], JSON_INVALID_UTF8_SUBSTITUTE);
                        if (is_string($line)) {
                            $this->output->writeln($line);
                        }
                    }
                }

                $usage = StreamEnd::combineUsage($events);
                $this->output->writeln(json_encode(['type' => 'end', 'tokens' => $usage->completionTokens], JSON_INVALID_UTF8_SUBSTITUTE));

                break;
            } catch (RateLimitedException $e) {
                $attempts++;
                if ($attempts < $maxAttempts) {
                    // Backoff: 250ms, 500ms...
                    usleep(250_000 * $attempts);

                    continue;
                }

                $this->output->writeln(json_encode([
                    'type' => 'error',
                    'http_code' => 429,
                    'detail' => $e->getMessage(),
                ], JSON_INVALID_UTF8_SUBSTITUTE));

                return self::FAILURE;
            } catch (RequestException $e) {
                $status = $e->response ? (int) $e->response->status() : 500;

                $this->output->writeln(json_encode([
                    'type' => 'error',
                    'http_code' => $status > 0 ? $status : 500,
                    'detail' => $e->getMessage(),
                ], JSON_INVALID_UTF8_SUBSTITUTE));

                return self::FAILURE;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $httpCode = 500;

                if (preg_match('/status code\s+(\d{3})/i', $msg, $m) === 1) {
                    $httpCode = (int) $m[1];
                } elseif (str_contains(strtolower($msg), 'rate limit')) {
                    $httpCode = 429;
                } elseif (str_contains(strtolower($msg), 'connection refused')) {
                    $httpCode = 503;
                }

                $this->output->writeln(json_encode([
                    'type' => 'error',
                    'http_code' => $httpCode,
                    'detail' => $msg,
                ], JSON_INVALID_UTF8_SUBSTITUTE));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\AI;

use App\Exceptions\AIProviderException;
use App\Exceptions\InvalidModelException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\Process\Process;

use function Laravel\Ai\agent;

class AIService
{
    /**
     * @return array<int, array{key: string, name: string, provider: string, model_id: string}>
     */
    private function configuredModels(): array
    {
        $models = (array) config('referee_ai.models', []);

        $out = [];
        foreach ($models as $key => $cfg) {
            if (! is_array($cfg)) {
                continue;
            }

            $out[] = [
                'key' => (string) $key,
                'name' => (string) ($cfg['name'] ?? $key),
                'provider' => (string) ($cfg['provider'] ?? ''),
                'model_id' => (string) ($cfg['model_id'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{id: string, name: string, provider: string, model_id: string}>
     */
    public function listModels(): array
    {
        $out = [];

        $seen = [];
        foreach ($this->configuredModels() as $m) {
            $modelId = trim($m['model_id']);
            if ($modelId === '') {
                continue;
            }
            if (isset($seen[$modelId])) {
                continue;
            }
            $seen[$modelId] = true;

            // Public API identifier is always model_id (not internal config keys).
            $out[] = [
                'id' => $modelId,
                'name' => $m['name'] !== '' ? $m['name'] : $modelId,
                'provider' => $m['provider'],
                'model_id' => $modelId,
            ];
        }

        return $out;
    }

    public function labelFor(string $identifier): string
    {
        $cfg = $this->findModelConfig($identifier);
        if (is_array($cfg)) {
            $name = trim((string) ($cfg['name'] ?? ''));
            $modelId = trim((string) ($cfg['model_id'] ?? ''));

            return $name !== '' ? $name : ($modelId !== '' ? $modelId : $identifier);
        }

        return $identifier;
    }

    /**
     * Stream panelists sequentially.
     *
     * @param  array<string>  $modelSlugs
     * @param  array<int, UploadedFile>  $attachments
     * @param  callable(int $index, string $chunk): void  $onChunk
     * @param  callable(int $index, int $tokens, string $content): void  $onComplete
     * @param  callable(int $index, int $httpCode, string $detail): void|null  $onError
     * @return array<int, string>
     */
    public function streamPanelists(
        array $modelSlugs,
        string $prompt,
        array $attachments,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError = null,
    ): array {
        return $this->streamPanelistsInParallel(
            $modelSlugs,
            $prompt,
            $attachments,
            $onChunk,
            $onComplete,
            $onError,
        );
    }

    /**
     * @param  array<string>  $modelSlugs
     * @param  array<int, UploadedFile>  $attachments
     * @param  callable(int $index, string $chunk): void  $onChunk
     * @param  callable(int $index, int $tokens, string $content): void  $onComplete
     * @param  callable(int $index, int $httpCode, string $detail): void|null  $onError
     * @return array<int, string>
     */
    private function streamPanelistsInParallel(
        array $modelSlugs,
        string $prompt,
        array $attachments,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError,
    ): array {
        // We stream multiple panelist models concurrently by launching a separate PHP process
        // for each model (Artisan command `ai:stream`). Each worker prints JSON lines
        // to stdout (delta/end/error), which we multiplex back into SSE events.
        if (config('app.debug')) {
            Log::debug('ai_panelists_parallel_start', [
                'count' => count($modelSlugs),
                'slugs' => array_values($modelSlugs),
                'attachments' => count($attachments),
            ]);
        }

        $tmpId = (string) Str::uuid();
        $dir = storage_path('app/ai-stream/'.$tmpId);
        File::ensureDirectoryExists($dir);

        $promptFile = $dir.'/prompt.txt';
        // Attachments are handled in two ways:
        // - For some doc types we extract text and append it to the prompt (cheap + portable)
        // - We also pass image/document files through to the provider via Laravel AI attachments
        [$promptWithAttachmentText, $filesForWorkers] = $this->preparePromptAndWorkerAttachments($prompt, $attachments);
        file_put_contents($promptFile, $promptWithAttachmentText);

        $attachmentsFile = $dir.'/attachments.json';
        $serialized = [];
        foreach ($filesForWorkers as $i => $file) {
            $name = (string) ($file['name'] ?? ('attachment-'.$i));
            $mime = (string) ($file['mime'] ?? 'application/octet-stream');
            $src = (string) ($file['path'] ?? '');
            $kind = (string) ($file['kind'] ?? 'document');

            if ($src === '' || ! is_file($src)) {
                continue;
            }

            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: ('attachment-'.$i);
            $dst = $dir.'/'.($i.'-'.$safeName);
            File::copy($src, $dst);

            $serialized[] = [
                'path' => $dst,
                'name' => $name,
                'mime' => $mime,
                'kind' => $kind,
            ];
        }
        file_put_contents($attachmentsFile, json_encode($serialized));

        $responses = [];
        $buffers = [];
        $done = [];

        $processes = [];
        foreach (array_values($modelSlugs) as $index => $slug) {
            $responses[$index] = '';
            $buffers[$index] = '';
            $done[$index] = false;

            try {
                [$provider, $model] = $this->resolveProviderAndModel($slug);
            } catch (\Throwable $e) {
                $done[$index] = true;
                if (is_callable($onError)) {
                    $onError($index, 422, $e->getMessage());
                }

                continue;
            }

            $php = $this->phpCliBinary();
            $artisan = base_path('artisan');

            $proc = new Process([
                $php,
                $artisan,
                'ai:stream',
                $provider,
                $model,
                $promptFile,
                $attachmentsFile,
            ]);
            $proc->setTimeout((int) config('referee_ai.timeout', 120) + 30);
            $proc->setWorkingDirectory(base_path());
            $proc->start();

            if (config('app.debug')) {
                Log::debug('ai_panelist_process_started', [
                    'index' => $index,
                    'slug' => $slug,
                    'cmd' => $proc->getCommandLine(),
                    'pid' => $proc->getPid(),
                ]);
            }
            $processes[$index] = $proc;
        }

        while (true) {
            $anyRunning = false;

            foreach ($processes as $index => $proc) {
                if (! $proc->isRunning()) {
                    if (! $done[$index]) {
                        // Consume any final output.
                        $buffers[$index] .= $proc->getIncrementalOutput();
                        $buffers[$index] .= $proc->getIncrementalErrorOutput();

                        if (config('app.debug')) {
                            Log::debug('ai_panelist_process_exited', [
                                'index' => $index,
                                'exit' => $proc->getExitCode(),
                                'ok' => $proc->isSuccessful(),
                                'out_len' => strlen($proc->getOutput()),
                                'err_len' => strlen($proc->getErrorOutput()),
                            ]);
                        }

                        $this->drainStreamLines(
                            $index,
                            $buffers,
                            $responses,
                            $done,
                            $onChunk,
                            $onComplete,
                            $onError,
                        );

                        if (! $done[$index]) {
                            $done[$index] = true;
                            if (! $proc->isSuccessful() && is_callable($onError)) {
                                Log::warning('ai_panelist_process_failed', [
                                    'index' => $index,
                                    'exit_code' => $proc->getExitCode(),
                                    'cmd' => $proc->getCommandLine(),
                                    'stdout' => $proc->getOutput(),
                                    'stderr' => $proc->getErrorOutput(),
                                    'prompt_file' => $promptFile,
                                    'attachments_file' => $attachmentsFile,
                                    'prompt_exists' => file_exists($promptFile),
                                    'attachments_exists' => file_exists($attachmentsFile),
                                    'prompt_readable' => is_readable($promptFile),
                                    'attachments_readable' => is_readable($attachmentsFile),
                                ]);

                                $errorText = trim($proc->getErrorOutput() ?: $proc->getOutput());

                                $onError($index, 500, $errorText !== '' ? $errorText : 'Stream failed');
                            }
                        }
                    }

                    continue;
                }

                $anyRunning = true;
                $buffers[$index] .= $proc->getIncrementalOutput();
                $buffers[$index] .= $proc->getIncrementalErrorOutput();
                $this->drainStreamLines(
                    $index,
                    $buffers,
                    $responses,
                    $done,
                    $onChunk,
                    $onComplete,
                    $onError,
                );
            }

            if (! $anyRunning) {
                break;
            }

            usleep(10_000);
        }

        $this->deleteDir($dir);

        return $responses;
    }

    private function phpCliBinary(): string
    {
        $bin = (string) PHP_BINARY;

        if ($bin === '') {
            return 'php';
        }

        $lower = strtolower($bin);

        // When running under FPM, PHP_BINARY can point at php-fpm (not the CLI binary).
        // We launch subprocesses, so we need the CLI binary (php/php8.x), not php-fpm.
        $base = strtolower(basename($lower));
        if (str_starts_with($base, 'php-fpm')) {
            // php-fpm8.4 -> php8.4, php-fpm -> php
            $candidateBase = str_replace('php-fpm', 'php', basename($bin));
            $candidate = dirname($bin).DIRECTORY_SEPARATOR.$candidateBase;
            if (is_file($candidate)) {
                return $candidate;
            }

            // Common Linux layout: php-fpm is in /usr/sbin while php is in /usr/bin.
            $altDir = str_replace(DIRECTORY_SEPARATOR.'sbin', DIRECTORY_SEPARATOR.'bin', dirname($bin));
            $altCandidate = $altDir.DIRECTORY_SEPARATOR.$candidateBase;
            if ($altDir !== dirname($bin) && is_file($altCandidate)) {
                return $altCandidate;
            }

            if (preg_match('/php-fpm(?P<ver>\d+\.\d+)$/i', $base, $m) === 1) {
                return 'php'.$m['ver'];
            }

            return 'php';
        }

        // In some environments PHP_BINARY points to php-cgi (FPM / CGI). Prefer the CLI binary.
        if (str_ends_with($lower, 'php-cgi.exe')) {
            $candidate = substr($bin, 0, -strlen('php-cgi.exe')).'php.exe';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        if (str_ends_with($lower, 'php-cgi')) {
            $candidate = substr($bin, 0, -strlen('php-cgi')).'php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $bin;
    }

    /**
     * @param  array<int, string>  $buffers
     * @param  array<int, string>  $responses
     * @param  array<int, bool>  $done
     */
    private function drainStreamLines(
        int $index,
        array &$buffers,
        array &$responses,
        array &$done,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError,
    ): void {
        while (true) {
            $pos = strpos($buffers[$index], "\n");
            if ($pos === false) {
                return;
            }

            $line = trim(substr($buffers[$index], 0, $pos));
            $buffers[$index] = substr($buffers[$index], $pos + 1);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            $type = (string) ($decoded['type'] ?? '');
            if ($type === 'delta') {
                $delta = (string) ($decoded['delta'] ?? '');
                if ($delta !== '' && ! $done[$index]) {
                    $responses[$index] .= $delta;
                    $onChunk($index, $delta);
                }

                continue;
            }

            if ($type === 'end' && ! $done[$index]) {
                $tokens = (int) ($decoded['tokens'] ?? 0);
                $done[$index] = true;
                $onComplete($index, $tokens, $responses[$index]);

                continue;
            }

            if ($type === 'error' && ! $done[$index]) {
                $done[$index] = true;
                if (is_callable($onError)) {
                    $onError($index, (int) ($decoded['http_code'] ?? 500), (string) ($decoded['detail'] ?? 'Error'));
                }
            }
        }
    }

    private function deleteDir(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * @param  array<int, UploadedFile>  $attachments
     * @return array{0: string, 1: array<int, array{path: string, name: string, mime: string, kind: string}>}
     */
    private function preparePromptAndWorkerAttachments(string $prompt, array $attachments): array
    {
        $maxFiles = (int) config('referee_ai.attachment_max_files', 3);
        $maxChars = (int) config('referee_ai.attachment_max_chars', 30_000);

        $filesForWorkers = [];
        $textBlocks = [];

        foreach (array_slice($attachments, 0, $maxFiles) as $i => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $name = (string) ($file->getClientOriginalName() ?: ('attachment-'.$i));
            $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
            $path = (string) ($file->getRealPath() ?: '');
            if ($path === '' || ! is_file($path)) {
                continue;
            }

            if (str_starts_with($mime, 'image/')) {
                $filesForWorkers[] = ['path' => $path, 'name' => $name, 'mime' => $mime, 'kind' => 'image'];

                continue;
            }

            $extracted = $this->extractAttachmentText($path, $mime);
            if ($extracted !== '') {
                $textBlocks[] = $this->formatAttachmentTextBlock($name, $mime, $extracted);

                continue;
            }

            // Fallback: pass as a document to the provider (if supported).
            $filesForWorkers[] = ['path' => $path, 'name' => $name, 'mime' => $mime, 'kind' => 'document'];
        }

        $suffix = '';
        if (count($textBlocks) > 0) {
            $combined = "\n\n---\nAttached files (extracted text)\n---\n\n".implode("\n\n", $textBlocks);
            if (strlen($combined) > $maxChars) {
                $combined = substr($combined, 0, $maxChars)."\n\n[Attachment text truncated]";
            }
            $suffix = $combined;
        }

        return [$prompt.$suffix, $filesForWorkers];
    }

    private function formatAttachmentTextBlock(string $name, string $mime, string $text): string
    {
        $text = $this->ensureUtf8($text);
        $clean = trim(preg_replace('/\r\n?/', "\n", $text) ?? '');

        return "File: {$name} ({$mime})\n\n".$clean;
    }

    private function extractAttachmentText(string $path, string $mime): string
    {
        if ($mime === 'application/pdf') {
            return $this->extractPdfText($path);
        }

        if ($mime === 'application/msword') {
            return $this->extractDocText($path);
        }

        if ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return $this->extractDocxText($path);
        }

        if ($mime === 'text/plain' || $mime === 'text/csv' || $mime === 'application/csv') {
            $raw = @file_get_contents($path);

            return is_string($raw) ? $this->ensureUtf8($raw) : '';
        }

        return '';
    }

    private function extractPdfText(string $path): string
    {
        // Best-effort: use pdftotext if available in PATH.
        try {
            $proc = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
            $proc->setTimeout(20);
            $proc->run();
            if ($proc->isSuccessful()) {
                return $this->ensureUtf8((string) $proc->getOutput());
            }
        } catch (\Throwable) {
            // ignore
        }

        return '';
    }

    private function extractDocxText(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            return '';
        }

        // Replace common Word run/paragraph boundaries with newlines/spaces, then strip tags.
        $xml = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);
        $xml = str_replace(['</w:tab>', '</w:tc>'], "\t", $xml);
        $text = strip_tags($xml);

        // Collapse excessive whitespace.
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($this->ensureUtf8($text));
    }

    private function extractDocText(string $path): string
    {
        // Best-effort: use antiword if available in PATH.
        try {
            $proc = new Process(['antiword', $path]);
            $proc->setTimeout(20);
            $proc->run();
            if ($proc->isSuccessful()) {
                return $this->ensureUtf8((string) $proc->getOutput());
            }
        } catch (\Throwable) {
            // ignore
        }

        // Windows fallback: if antiword is installed in WSL, use wsl.exe to run it.
        if (PHP_OS_FAMILY === 'Windows') {
            $text = $this->extractDocTextViaWsl($path);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function extractDocTextViaWsl(string $windowsPath): string
    {
        try {
            $distro = (string) (config('referee_ai.wsl_distro') ?: '');

            if (config('app.debug')) {
                Log::debug('ai_attachment_doc_wsl_antiword_attempt', [
                    'distro' => $distro !== '' ? $distro : null,
                    'path' => $windowsPath,
                ]);
            }

            $wslpathArgs = ['wsl.exe'];
            if ($distro !== '') {
                $wslpathArgs[] = '-d';
                $wslpathArgs[] = $distro;
            }
            $wslpathArgs = array_merge($wslpathArgs, ['-e', 'wslpath', '-u', $windowsPath]);

            $wslPathProc = new Process($wslpathArgs);
            $wslPathProc->setTimeout(10);
            $wslPathProc->run();
            if (! $wslPathProc->isSuccessful()) {
                return '';
            }

            $wslPath = trim((string) $wslPathProc->getOutput());
            if ($wslPath === '') {
                return '';
            }

            if (config('app.debug')) {
                Log::debug('ai_attachment_doc_wsl_path_resolved', [
                    'distro' => $distro !== '' ? $distro : null,
                    'wsl_path' => $wslPath,
                ]);
            }

            $antiwordArgs = ['wsl.exe'];
            if ($distro !== '') {
                $antiwordArgs[] = '-d';
                $antiwordArgs[] = $distro;
            }
            $antiwordArgs = array_merge($antiwordArgs, ['-e', 'antiword', $wslPath]);

            $proc = new Process($antiwordArgs);
            $proc->setTimeout(20);
            $proc->run();
            if (! $proc->isSuccessful()) {
                return '';
            }

            $out = (string) $proc->getOutput();
            if (trim($out) === '') {
                return '';
            }

            if (config('app.debug')) {
                Log::debug('ai_attachment_doc_wsl_antiword_success', [
                    'distro' => $distro !== '' ? $distro : null,
                    'bytes' => strlen($out),
                ]);
            }

            return $this->ensureUtf8($out);
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::debug('ai_attachment_doc_wsl_antiword_failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            return '';
        }
    }

    private function ensureUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Remove NUL bytes which often break JSON encoding.
        $text = str_replace("\0", '', $text);

        // Fast path: already valid UTF-8.
        if (@preg_match('//u', $text) === 1) {
            return $text;
        }

        // Best-effort conversion. mbstring is preferred if available.
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8,Windows-1252,ISO-8859-1');
            if (is_string($converted) && @preg_match('//u', $converted) === 1) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '' && @preg_match('//u', $converted) === 1) {
                return $converted;
            }
        }

        // Last resort: drop non-ASCII bytes.
        return (string) @preg_replace('/[^\x00-\x7F]/', '', $text);
    }

    /**
     * Stream a single model.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function streamSingle(
        string $modelSlug,
        string $prompt,
        array $attachments,
        callable $onChunk,
    ): string {
        // Single-model streaming using the Laravel AI SDK.
        [$provider, $model] = $this->resolveProviderAndModel($modelSlug);
        $timeout = (int) config('referee_ai.timeout', 120);

        [$promptWithAttachmentText, $files] = $this->preparePromptAndWorkerAttachments($prompt, $attachments);
        $aiAttachments = $this->buildAiAttachments($files);

        $events = [];
        $text = '';
        $stream = agent()->stream(
            $promptWithAttachmentText,
            provider: $provider,
            model: $model,
            attachments: $aiAttachments,
            timeout: $timeout,
        );

        foreach ($stream as $event) {
            $events[] = $event;
            if ($event instanceof TextDelta) {
                $text .= $event->delta;
                $onChunk($event->delta);
            }
        }

        return $text;
    }

    /**
     * Prompt a model without streaming.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function complete(string $modelSlug, string $prompt, array $attachments = []): string
    {
        // Non-streaming call using the Laravel AI SDK (used for auto-titling sessions).
        [$provider, $model] = $this->resolveProviderAndModel($modelSlug);
        $timeout = (int) config('referee_ai.timeout', 120);

        [$promptWithAttachmentText, $files] = $this->preparePromptAndWorkerAttachments($prompt, $attachments);
        $aiAttachments = $this->buildAiAttachments($files);

        $res = agent()->prompt(
            $promptWithAttachmentText,
            provider: $provider,
            model: $model,
            attachments: $aiAttachments,
            timeout: $timeout,
        );

        return (string) $res;
    }

    /**
     * @param  array<int, array{path: string, name: string, mime: string, kind: string}>  $files
     * @return array<int, Files\Image|Files\Document>
     */
    private function buildAiAttachments(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || ! is_file($path)) {
                continue;
            }

            $kind = (string) ($file['kind'] ?? 'document');
            $mime = (string) ($file['mime'] ?? '');

            if ($kind === 'image' || str_starts_with($mime, 'image/')) {
                $out[] = Files\Image::fromPath($path);

                continue;
            }

            $out[] = Files\Document::fromPath($path);
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveProviderAndModel(string $slug): array
    {
        $cfg = $this->findModelConfig($slug);
        if (! is_array($cfg)) {
            throw InvalidModelException::forSlug($slug);
        }

        $provider = (string) ($cfg['provider'] ?? 'openai');
        $model = (string) ($cfg['model_id'] ?? '');

        if ($model === '') {
            throw AIProviderException::forProvider($provider, "Missing model_id for slug '{$slug}'");
        }

        return [$provider, $model];
    }

    /**
     * Find a model config entry for either an internal key (legacy) or a public model_id.
     *
     * @return array<string, mixed>|null
     */
    private function findModelConfig(string $identifier): ?array
    {
        $models = (array) config('referee_ai.models', []);

        // 1) Exact key match (legacy sessions may store internal keys).
        $direct = $models[$identifier] ?? null;
        if (is_array($direct)) {
            return $direct;
        }

        $id = trim($identifier);
        if ($id === '') {
            return null;
        }

        // 2) Match by model_id (preferred sessions store model_id).
        foreach ($models as $cfg) {
            if (! is_array($cfg)) {
                continue;
            }
            if (trim((string) ($cfg['model_id'] ?? '')) === $id) {
                return $cfg;
            }
        }

        return null;
    }
}

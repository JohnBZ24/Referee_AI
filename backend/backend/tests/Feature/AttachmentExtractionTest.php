<?php

use App\Services\AI\AIService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

it('inlines docx text into the prompt', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive extension not available.');
    }

    config()->set('referee_ai.attachment_max_chars', 30_000);
    config()->set('referee_ai.attachment_max_files', 3);

    $tmp = sys_get_temp_dir().'/referee-ai-test-'.bin2hex(random_bytes(8));
    @mkdir($tmp, 0777, true);
    $docxPath = $tmp.'/sample.docx';

    $zip = new ZipArchive;
    expect($zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Hello DOCX</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();

    $uploaded = new UploadedFile(
        $docxPath,
        'sample.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        null,
        true,
    );

    $service = app(AIService::class);
    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('preparePromptAndWorkerAttachments');
    $method->setAccessible(true);

    [$prompt, $files] = $method->invoke($service, 'Base prompt', [$uploaded]);

    expect($prompt)->toContain('Attached files (extracted text)');
    expect($prompt)->toContain('Hello DOCX');
    expect($files)->toBeArray();
});

it('attempts WSL antiword for .doc on Windows', function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('Windows-only test.');
    }

    if (! is_string(shell_exec('wsl.exe -l -q 2>NUL'))) {
        $this->markTestSkipped('WSL not available.');
    }

    config()->set('referee_ai.wsl_distro', null);

    $tmp = sys_get_temp_dir().'/referee-ai-test-'.bin2hex(random_bytes(8));
    @mkdir($tmp, 0777, true);
    $docPath = $tmp.'/sample.doc';
    file_put_contents($docPath, 'NOT_A_REAL_DOC');

    Log::spy();

    $service = app(AIService::class);
    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('extractDocTextViaWsl');
    $method->setAccessible(true);
    $method->invoke($service, $docPath);

    Log::shouldHaveReceived('debug')->withArgs(function (string $message, array $context = []) {
        return $message === 'ai_attachment_doc_wsl_antiword_attempt';
    });
});

it('sanitizes invalid utf-8 from text attachments', function () {
    config()->set('referee_ai.attachment_max_chars', 30_000);
    config()->set('referee_ai.attachment_max_files', 3);

    $tmp = sys_get_temp_dir().'/referee-ai-test-'.bin2hex(random_bytes(8));
    @mkdir($tmp, 0777, true);
    $txtPath = $tmp.'/bad.txt';

    // Intentionally invalid UTF-8 sequence.
    file_put_contents($txtPath, "Hello \xC3\x28 world");

    $uploaded = new UploadedFile(
        $txtPath,
        'bad.txt',
        'text/plain',
        null,
        true,
    );

    $service = app(AIService::class);
    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('preparePromptAndWorkerAttachments');
    $method->setAccessible(true);

    [$prompt] = $method->invoke($service, 'Base prompt', [$uploaded]);

    expect(preg_match('//u', $prompt))->toBe(1);
});

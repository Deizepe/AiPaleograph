<?php

namespace App\Jobs;

use App\Models\ProjectPage;
use App\Services\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TranscribeProjectPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $projectPageId) {}

    public function handle(OpenRouterService $openRouter): void
    {
        $page = ProjectPage::query()->with('project.user.settings')->find($this->projectPageId);
        if (! $page) {
            return;
        }

        $user = $page->project->user;
        $settings = $user->settings;
        if (! $settings || empty($settings->openrouter_api_key)) {
            $page->update([
                'transcription_status' => ProjectPage::STATUS_FAILED,
                'transcription_error' => 'OpenRouter API key is not configured. Add it in Settings.',
            ]);

            return;
        }

        $page->update([
            'transcription_status' => ProjectPage::STATUS_PROCESSING,
            'transcription_error' => null,
        ]);

        $disk = Storage::disk(config('filesystems.default'));
        $binary = $disk->get($page->object_key);
        if ($binary === false || $binary === null || $binary === '') {
            throw new \RuntimeException(
                'Could not read page image from storage (missing or empty). '
                .'Confirm MinIO is reachable, the bucket exists, FILESYSTEM_DISK=minio, '
                .'and AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY match MINIO_ROOT_USER / MINIO_ROOT_PASSWORD.'
            );
        }

        $base64 = base64_encode($binary);

        $result = $openRouter->transcribeManuscriptPage(
            $base64,
            $page->mime_type,
            $settings->translation_language,
            $settings->openrouter_api_key,
            $settings->openrouter_model,
        );

        $parseFailed = (bool) ($result['_parse_failed'] ?? false);
        $processingTimeMs = isset($result['processing_time_ms']) ? (int) $result['processing_time_ms'] : null;
        $processingCostUsd = isset($result['processing_cost_usd']) ? (float) $result['processing_cost_usd'] : null;
        unset($result['_parse_failed']);

        $page->update([
            'original_text' => $result['original_text'],
            'transcribed_text' => $result['transcribed_text'],
            'processing_time_ms' => $processingTimeMs,
            'processing_cost_usd' => $processingCostUsd,
            'transcription_status' => $parseFailed ? ProjectPage::STATUS_FAILED : ProjectPage::STATUS_COMPLETED,
            'transcription_error' => $parseFailed
                ? 'Parse failed — full OpenRouter response and extracted text chunks are stored in the Original text column.'
                : null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $page = ProjectPage::query()->find($this->projectPageId);
        if (! $page) {
            return;
        }

        $page->update([
            'transcription_status' => ProjectPage::STATUS_FAILED,
            'transcription_error' => $exception?->getMessage() ?? 'Unknown error',
        ]);
    }
}

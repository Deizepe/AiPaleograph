<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectPdfController extends Controller
{
    public function full(Project $project): Response
    {
        $this->authorizeProject($project);
        $project->load(['pages' => fn ($q) => $q->orderBy('page_number')]);

        $viewData = $this->baseViewData($project);
        $pdf = Pdf::loadView('pdf.project-full', $viewData)->setPaper('a4', 'landscape');

        return response($pdf->output(), 200, $this->downloadHeaders($project, 'full'));
    }

    public function original(Project $project): Response
    {
        $this->authorizeProject($project);
        $project->load(['pages' => fn ($q) => $q->orderBy('page_number')]);

        $viewData = $this->baseViewData($project);
        $pdf = Pdf::loadView('pdf.project-original', $viewData)->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, $this->downloadHeaders($project, 'original'));
    }

    public function transcribed(Project $project): Response
    {
        $this->authorizeProject($project);
        $project->load(['pages' => fn ($q) => $q->orderBy('page_number')]);

        $viewData = $this->baseViewData($project);
        $pdf = Pdf::loadView('pdf.project-transcribed', $viewData)->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, $this->downloadHeaders($project, 'transcribed'));
    }

    public function fullWord(Project $project): Response
    {
        $this->authorizeProject($project);
        $project->load(['pages' => fn ($q) => $q->orderBy('page_number')]);

        $html = view('word.project-full', $this->baseViewData($project))->render();

        return response($html, 200, $this->wordHeaders($project, 'full'));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseViewData(Project $project): array
    {
        $disk = Storage::disk(config('filesystems.default'));

        $pages = $project->pages->map(function ($page) use ($disk) {
            $imageDataUrl = null;
            try {
                if ($disk->exists($page->object_key)) {
                    $binary = $disk->get($page->object_key);
                    $imageDataUrl = 'data:'.$page->mime_type.';base64,'.base64_encode($binary);
                }
            } catch (\Throwable) {
                $imageDataUrl = null;
            }

            return [
                'page_number' => $page->page_number,
                'original_filename' => $page->original_filename,
                'image_data_url' => $imageDataUrl,
                'original_text' => $this->formatText($page->original_text),
                'transcribed_text' => $this->formatText($page->transcribed_text),
                'notes' => $this->formatText($page->observations),
            ];
        })->values();

        return [
            'project' => $project,
            'pages' => $pages,
            'generated_at' => now(),
            'app_name' => config('app.name', 'AiPaleograph'),
        ];
    }

    private function formatText(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        // Keep basic rich formatting from TinyMCE while removing unsupported tags.
        $sanitized = strip_tags($text, '<p><br><strong><b><em><i><u><a><ul><ol><li>');

        return $sanitized === '' ? nl2br(e($text)) : $sanitized;
    }

    /**
     * @return array<string, string>
     */
    private function downloadHeaders(Project $project, string $kind): array
    {
        $slug = Str::slug($project->name ?: 'project');
        $filename = $slug.'-'.$kind.'.pdf';

        return [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function wordHeaders(Project $project, string $kind): array
    {
        $slug = Str::slug($project->name ?: 'project');
        $filename = $slug.'-'.$kind.'.doc';

        return [
            'Content-Type' => 'application/msword; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];
    }

    private function authorizeProject(Project $project): void
    {
        abort_unless($project->user_id === auth()->id(), 403);
    }
}

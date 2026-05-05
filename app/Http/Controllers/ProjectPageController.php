<?php

namespace App\Http\Controllers;

use App\Jobs\TranscribeProjectPageJob;
use App\Models\Project;
use App\Models\ProjectPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectPageController extends Controller
{
    public function data(Project $project): JsonResponse
    {
        abort_unless($project->user_id === auth()->id(), 403);

        $pages = $project->pages()
            ->orderBy('page_number')
            ->get()
            ->values();

        $lastIndex = $pages->count() - 1;
        $rows = $pages->map(function (ProjectPage $page, int $index) use ($project, $lastIndex) {
                $badge = match ($page->transcription_status) {
                    ProjectPage::STATUS_COMPLETED => 'success',
                    ProjectPage::STATUS_PROCESSING => 'info',
                    ProjectPage::STATUS_FAILED => 'danger',
                    default => 'warning',
                };

                return [
                    'id' => $page->id,
                    'page_number' => $page->page_number,
                    'max_page' => $lastIndex + 1,
                    'original_filename' => $page->original_filename,
                    'image_url' => route('projects.pages.image', [$project, $page]),
                    'original_text' => $page->original_text ?? '',
                    'transcribed_text' => $page->transcribed_text ?? '',
                    'notes' => $page->observations ?? '',
                    'status' => $page->transcription_status,
                    'badge_class' => $badge,
                    'processing_time_ms' => $page->processing_time_ms,
                    'processing_cost_usd' => $page->processing_cost_usd !== null
                        ? (float) $page->processing_cost_usd
                        : null,
                    'error_short' => $page->transcription_error
                        ? Str::limit($page->transcription_error, 180)
                        : '',
                    'update_url' => route('projects.pages.update', [$project, $page]),
                    'move_up_url' => $index > 0
                        ? route('projects.pages.move-up', [$project, $page])
                        : null,
                    'move_down_url' => $index < $lastIndex
                        ? route('projects.pages.move-down', [$project, $page])
                        : null,
                    'move_to_url' => route('projects.pages.move-to', [$project, $page]),
                    'retranscribe_url' => route('projects.pages.retranscribe', [$project, $page]),
                    'destroy_url' => route('projects.pages.destroy', [$project, $page]),
                ];
            });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === auth()->id(), 403);

        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,tif,tiff,bmp', 'max:25600'],
        ]);

        $disk = Storage::disk(config('filesystems.default'));
        $createdIds = [];

        // Temporary page numbers must be unique per (project_id, page_number). Using a high
        // range avoids collisions in the same batch and stays within unsignedInteger until
        // recalculatePageNumbers() rewrites to 1..n ordered by filename.
        $maxPage = (int) ($project->pages()->max('page_number') ?? 0);
        $tempBase = max(1_000_000, $maxPage + 1_000_000);

        foreach ($request->file('files') as $index => $file) {
            $ext = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
            $basename = (string) Str::uuid();
            $objectKey = 'projects/'.$project->id.'/'.$basename.'.'.$ext;
            $disk->put($objectKey, file_get_contents($file->getRealPath()));

            $page = ProjectPage::create([
                'project_id' => $project->id,
                'page_number' => $tempBase + $index,
                'original_filename' => $file->getClientOriginalName(),
                'object_key' => $objectKey,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'transcription_status' => ProjectPage::STATUS_PENDING,
            ]);
            $createdIds[] = $page->id;
        }

        $project->recalculatePageNumbers();

        foreach ($createdIds as $id) {
            TranscribeProjectPageJob::dispatch($id);
        }

        return redirect()->route('projects.show', $project)->with('status', 'Images uploaded. Transcription has been queued.');
    }

    public function update(Request $request, Project $project, ProjectPage $page): JsonResponse
    {
        abort_unless($project->user_id === auth()->id(), 403);
        abort_unless($page->project_id === $project->id, 404);

        $validated = $request->validate([
            'original_text' => ['nullable', 'string'],
            'transcribed_text' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $page->update([
            'original_text' => $validated['original_text'] ?? null,
            'transcribed_text' => $validated['transcribed_text'] ?? null,
            'observations' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Page texts updated.',
        ]);
    }

    public function moveUp(Project $project, ProjectPage $page): JsonResponse
    {
        abort_unless($project->user_id === auth()->id(), 403);
        abort_unless($page->project_id === $project->id, 404);

        $moved = $this->movePage($project, $page, 'up');

        return response()->json([
            'ok' => true,
            'moved' => $moved,
        ]);
    }

    public function moveDown(Project $project, ProjectPage $page): JsonResponse
    {
        abort_unless($project->user_id === auth()->id(), 403);
        abort_unless($page->project_id === $project->id, 404);

        $moved = $this->movePage($project, $page, 'down');

        return response()->json([
            'ok' => true,
            'moved' => $moved,
        ]);
    }

    public function moveTo(Request $request, Project $project, ProjectPage $page): JsonResponse
    {
        abort_unless($project->user_id === auth()->id(), 403);
        abort_unless($page->project_id === $project->id, 404);

        $validated = $request->validate([
            'target_page' => ['required', 'integer'],
        ]);

        $moved = $this->movePageToPosition($project, $page, (int) $validated['target_page']);

        return response()->json([
            'ok' => true,
            'moved' => $moved,
        ]);
    }

    public function image(Project $project, ProjectPage $page): StreamedResponse|Response
    {
        abort_unless($project->user_id === auth()->id(), 403);
        abort_unless($page->project_id === $project->id, 404);

        return Storage::disk(config('filesystems.default'))->response(
            $page->object_key,
            $page->original_filename,
            ['Content-Type' => $page->mime_type]
        );
    }

    public function retranscribe(Project $project, ProjectPage $page): RedirectResponse
    {
        abort_unless($project->user_id === auth()->id(), 403);
        abort_unless($page->project_id === $project->id, 404);

        $page->update([
            'transcription_status' => ProjectPage::STATUS_PENDING,
            'transcription_error' => null,
        ]);

        TranscribeProjectPageJob::dispatch($page->id);

        return redirect()->route('projects.show', $project)->with('status', 'Transcription re-queued for this page.');
    }

    public function destroy(Project $project, ProjectPage $page): RedirectResponse
    {
        abort_unless($project->user_id === auth()->id(), 403);
        abort_unless($page->project_id === $project->id, 404);

        Storage::disk(config('filesystems.default'))->delete($page->object_key);
        $page->delete();
        $project->recalculatePageNumbers();

        return redirect()->route('projects.show', $project)->with('status', 'Page removed.');
    }

    private function movePage(Project $project, ProjectPage $page, string $direction): bool
    {
        $siblingQuery = $project->pages()->orderBy('page_number');
        if ($direction === 'up') {
            $target = (clone $siblingQuery)
                ->where('page_number', '<', $page->page_number)
                ->orderByDesc('page_number')
                ->first();
        } else {
            $target = (clone $siblingQuery)
                ->where('page_number', '>', $page->page_number)
                ->orderBy('page_number')
                ->first();
        }

        if (! $target) {
            return false;
        }

        DB::transaction(function () use ($project, $page, $target) {
            $temp = (int) (($project->pages()->max('page_number') ?? 0) + 1_000_000);
            $currentNumber = $page->page_number;
            $targetNumber = $target->page_number;

            $page->update(['page_number' => $temp]);
            $target->update(['page_number' => $currentNumber]);
            $page->update(['page_number' => $targetNumber]);
        });

        return true;
    }

    private function movePageToPosition(Project $project, ProjectPage $page, int $targetPage): bool
    {
        $pages = $project->pages()->orderBy('page_number')->get()->values();
        $count = $pages->count();
        if ($count <= 1) {
            return false;
        }

        $fromIndex = $pages->search(fn (ProjectPage $p) => $p->id === $page->id);
        if (! is_int($fromIndex)) {
            return false;
        }

        $targetIndex = max(0, min($count - 1, $targetPage - 1));
        if ($fromIndex === $targetIndex) {
            return false;
        }

        $ids = $pages->pluck('id')->all();
        $movingId = $page->id;
        array_splice($ids, $fromIndex, 1);
        array_splice($ids, $targetIndex, 0, [$movingId]);

        DB::transaction(function () use ($project, $ids) {
            $baseTemp = (int) (($project->pages()->max('page_number') ?? 0) + 1_000_000);
            foreach ($ids as $index => $id) {
                ProjectPage::query()->whereKey($id)->update(['page_number' => $baseTemp + $index]);
            }
            foreach ($ids as $index => $id) {
                ProjectPage::query()->whereKey($id)->update(['page_number' => $index + 1]);
            }
        });

        return true;
    }
}

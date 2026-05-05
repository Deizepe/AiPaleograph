<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        $projects = auth()->user()->projects()->latest()->withCount('pages')->paginate(15);

        return view('app.projects.index', compact('projects'));
    }

    public function create(): View
    {
        return view('app.projects.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
        ]);

        $project = auth()->user()->projects()->create($validated);

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    public function show(Project $project): View
    {
        $this->authorizeProject($project);
        $project->loadCount('pages');

        return view('app.projects.show', compact('project'));
    }

    public function edit(Project $project): View
    {
        $this->authorizeProject($project);

        return view('app.projects.edit', compact('project'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
        ]);

        $project->update($validated);

        return redirect()->route('projects.show', $project)->with('status', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        $disk = Storage::disk(config('filesystems.default'));
        foreach ($project->pages as $page) {
            $disk->delete($page->object_key);
        }

        $project->delete();

        return redirect()->route('projects.index')->with('status', 'Project deleted.');
    }

    private function authorizeProject(Project $project): void
    {
        abort_unless($project->user_id === auth()->id(), 403);
    }
}

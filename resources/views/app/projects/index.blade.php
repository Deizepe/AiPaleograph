@extends('layouts/contentNavbarLayout')

@section('title', 'Projects')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-6 flex-wrap gap-2">
    <div>
        <h4 class="mb-1">Projects</h4>
        <p class="text-body-secondary mb-0">Each project holds ordered manuscript pages and transcriptions.</p>
    </div>
    <a href="{{ route('projects.create') }}" class="btn btn-primary">New project</a>
</div>

<div class="card">
    <div class="table-responsive text-nowrap">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Pages</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody class="table-border-bottom-0">
                @forelse ($projects as $project)
                <tr>
                    <td><strong>{{ $project->name }}</strong></td>
                    <td class="text-truncate" style="max-width: 320px">{{ $project->description }}</td>
                    <td>{{ $project->pages_count }}</td>
                    <td>{{ $project->updated_at->diffForHumans() }}</td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('projects.show', $project) }}">Open</a>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('projects.edit', $project) }}">Edit</a>
                        <form action="{{ route('projects.destroy', $project) }}" method="post" class="d-inline" onsubmit="return confirm('Delete this project and all pages?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-body-secondary py-6">No projects yet. Create one to start uploading pages.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($projects->hasPages())
    <div class="card-body">{{ $projects->links() }}</div>
    @endif
</div>
@endsection

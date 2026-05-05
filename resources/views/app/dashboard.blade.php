@extends('layouts/contentNavbarLayout')

@section('title', 'Dashboard')

@section('content')
<div class="row">
    <div class="col-12 mb-6">
        <h4 class="fw-bold mb-1">Welcome, {{ auth()->user()->name }}</h4>
        <p class="text-body-secondary mb-0">Manage manuscript projects, batch-upload page images, and run AI-assisted transcriptions via OpenRouter.</p>
    </div>
</div>

<div class="row g-4 mb-2">
    <div class="col-sm-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="d-block mb-1 text-body-secondary">Projects</span>
                        <h3 class="mb-0 fw-bold">{{ number_format($projectsCount) }}</h3>
                        <small class="text-body-secondary">Total manuscript projects</small>
                    </div>
                    <div class="avatar flex-shrink-0">
                        <span class="avatar-initial rounded bg-label-primary"><i class="icon-base bx bx-folder"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="d-block mb-1 text-body-secondary">Page images</span>
                        <h3 class="mb-0 fw-bold">{{ number_format($pageImagesCount) }}</h3>
                        <small class="text-body-secondary">Total uploaded across your projects</small>
                    </div>
                    <div class="avatar flex-shrink-0">
                        <span class="avatar-initial rounded bg-label-success"><i class="icon-base bx bx-image"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1 me-2">
                        <span class="d-block mb-1 text-body-secondary">OpenRouter balance</span>
                        @if ($openrouterCredits === null)
                            <h3 class="mb-0 fw-bold text-body-secondary">—</h3>
                            <small class="text-body-secondary">Add your API key in <a href="{{ route('settings.edit') }}">Settings</a> to see credits.</small>
                        @elseif (!empty($openrouterCredits['ok']))
                            <h3 class="mb-0 fw-bold">${{ number_format($openrouterCredits['remaining'], 2) }}</h3>
                            <small class="text-body-secondary">Remaining (purchased ${{ number_format($openrouterCredits['total_credits'], 2) }}, used ${{ number_format($openrouterCredits['total_usage'], 2) }})</small>
                        @else
                            <h3 class="mb-0 fw-bold text-warning">—</h3>
                            <small class="text-body-secondary d-block">{{ $openrouterCredits['message'] ?? 'Could not load credits.' }}</small>
                            <small class="text-body-secondary">Ensure your key can access the <a href="https://openrouter.ai/docs/api/api-reference/credits/get-credits" target="_blank" rel="noopener">credits API</a>.</small>
                        @endif
                    </div>
                    <div class="avatar flex-shrink-0">
                        <span class="avatar-initial rounded bg-label-warning"><i class="icon-base bx bx-wallet"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-2">
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h5 class="card-title mb-1">Projects</h5>
                        <p class="mb-0 text-body-secondary">Create a project for each manuscript or corpus.</p>
                    </div>
                    <div class="avatar flex-shrink-0">
                        <span class="avatar-initial rounded bg-label-primary"><i class="icon-base bx bx-book-content"></i></span>
                    </div>
                </div>
                <a href="{{ route('projects.index') }}" class="btn btn-sm btn-primary">View projects</a>
                <a href="{{ route('projects.create') }}" class="btn btn-sm btn-outline-primary ms-2">New project</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h5 class="card-title mb-1">OpenRouter</h5>
                        <p class="mb-0 text-body-secondary">Configure your API key, model, and contemporary translation language.</p>
                    </div>
                    <div class="avatar flex-shrink-0">
                        <span class="avatar-initial rounded bg-label-info"><i class="icon-base bx bx-slider-alt"></i></span>
                    </div>
                </div>
                <a href="{{ route('settings.edit') }}" class="btn btn-sm btn-primary">Open settings</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h5 class="card-title mb-1">Profile</h5>
                        <p class="mb-0 text-body-secondary">Signed in as <strong>{{ auth()->user()->name }}</strong></p>
                        <p class="mb-0 text-body-secondary small">{{ auth()->user()->email }}</p>
                    </div>
                    <div class="avatar flex-shrink-0">
                        <span class="avatar-initial rounded bg-label-secondary"><i class="icon-base bx bx-user"></i></span>
                    </div>
                </div>
                <a href="{{ route('settings.edit') }}" class="btn btn-sm btn-primary">Account &amp; API settings</a>
            </div>
        </div>
    </div>
</div>

@if ($projects->isNotEmpty())
<div class="card mt-6">
    <div class="card-header"><h5 class="mb-0">Recent projects</h5></div>
    <div class="table-responsive text-nowrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="table-border-bottom-0">
                @foreach ($projects as $project)
                <tr>
                    <td><strong>{{ $project->name }}</strong></td>
                    <td>{{ $project->updated_at->diffForHumans() }}</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('projects.show', $project) }}">Open</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection

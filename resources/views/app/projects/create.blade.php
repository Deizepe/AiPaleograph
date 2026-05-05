@extends('layouts/contentNavbarLayout')

@section('title', 'New project')

@section('content')
<div class="mb-6">
    <h4 class="mb-1">New project</h4>
    <p class="text-body-secondary mb-0">Add a name and optional description. You can upload page images after saving.</p>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ route('projects.store') }}" method="post">
            @csrf
            <div class="mb-4">
                <label class="form-label" for="name">Name</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label" for="description">Description</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4">{{ old('description') }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Create project</button>
            <a href="{{ route('projects.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
        </form>
    </div>
</div>
@endsection

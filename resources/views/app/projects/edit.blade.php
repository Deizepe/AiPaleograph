@extends('layouts/contentNavbarLayout')

@section('title', 'Edit project')

@section('content')
<div class="mb-6">
    <h4 class="mb-1">Edit project</h4>
    <p class="text-body-secondary mb-0">Update the manuscript metadata.</p>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ route('projects.update', $project) }}" method="post">
            @csrf
            @method('PUT')
            <div class="mb-4">
                <label class="form-label" for="name">Name</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $project->name) }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label" for="description">Description</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4">{{ old('description', $project->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-secondary ms-2">Back to pages</a>
        </form>
    </div>
</div>
@endsection

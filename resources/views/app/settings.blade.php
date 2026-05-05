@extends('layouts/contentNavbarLayout')

@section('title', 'Settings')

@section('content')
<div class="mb-6">
    <h4 class="mb-1">Settings</h4>
    <p class="text-body-secondary mb-0">Configure OpenRouter and the target language for contemporary transcriptions.</p>
</div>

@if (session('status'))
<div class="alert alert-success mb-4">{{ session('status') }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form action="{{ route('settings.update') }}" method="post">
            @csrf
            @method('PUT')

            <div class="mb-4">
                <label class="form-label" for="openrouter_api_key">OpenRouter API key</label>
                <input type="password" class="form-control @error('openrouter_api_key') is-invalid @enderror" id="openrouter_api_key" name="openrouter_api_key" autocomplete="off" placeholder="Leave blank to keep the current key">
                @error('openrouter_api_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Stored encrypted. Get a key from <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>.</div>
            </div>

            <div class="mb-4">
                @php
                    $selectedModel = old('openrouter_model', $settings->openrouter_model ?? \App\Models\UserSetting::DEFAULT_OPENROUTER_MODEL);
                @endphp
                <label class="form-label" for="openrouter_model">OpenRouter model (vision)</label>
                <select class="form-select @error('openrouter_model') is-invalid @enderror" id="openrouter_model" name="openrouter_model" required>
                    @foreach ($visionModels as $id)
                        <option value="{{ $id }}" @selected($selectedModel === $id)>{{ $id }}</option>
                    @endforeach
                    @if ($selectedModel !== '' && ! in_array($selectedModel, $visionModels, true))
                        <option value="{{ $selectedModel }}" selected>{{ $selectedModel }} (saved)</option>
                    @endif
                </select>
                @error('openrouter_model')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Only models that accept image input on OpenRouter are listed. List refreshes about every hour.</div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="translation_language">Contemporary translation language</label>
                <input type="text" class="form-control @error('translation_language') is-invalid @enderror" id="translation_language" name="translation_language" value="{{ old('translation_language', $settings->translation_language) }}" required list="translation-language-suggestions">
                <datalist id="translation-language-suggestions">
                    @foreach ($languageSuggestions as $lang)
                    <option value="{{ $lang }}"></option>
                    @endforeach
                </datalist>
                @error('translation_language')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">The model will produce a modern version in this language (for example, contemporary Portuguese).</div>
            </div>

            <button type="submit" class="btn btn-primary">Save settings</button>
        </form>
    </div>
</div>
@endsection

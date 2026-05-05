<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use App\Services\OpenRouterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * Used when OpenRouter /models cannot be loaded.
     *
     * @var list<string>
     */
    public const FALLBACK_VISION_MODELS = [
        'openai/gpt-5.4',
        'openai/gpt-5.4-mini',
        'openai/gpt-5.4-pro',
        'openai/gpt-4o',
        'openai/gpt-4o-mini',
        'google/gemini-2.0-flash-001',
        'anthropic/claude-3.5-sonnet',
    ];

    /** @var list<string> */
    public const SUGGESTED_LANGUAGES = [
        'Portuguese (Brazil)',
        'Portuguese (Portugal)',
        'English',
        'Spanish',
        'French',
        'Italian',
        'German',
        'Latin',
    ];

    public function edit(OpenRouterService $openRouter): View
    {
        $user = auth()->user();
        $settings = $user->settings ?? new UserSetting([
            'user_id' => $user->id,
            'openrouter_model' => UserSetting::DEFAULT_OPENROUTER_MODEL,
            'translation_language' => 'Portuguese (Brazil)',
        ]);

        $visionModels = Cache::remember('openrouter_vision_model_ids', 3600, function () use ($openRouter) {
            return $openRouter->listVisionCapableModelIds();
        });

        if ($visionModels === []) {
            $visionModels = self::FALLBACK_VISION_MODELS;
        }

        return view('app.settings', [
            'settings' => $settings,
            'languageSuggestions' => self::SUGGESTED_LANGUAGES,
            'visionModels' => $visionModels,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'openrouter_api_key' => ['nullable', 'string', 'max:2000'],
            'openrouter_model' => ['required', 'string', 'max:255'],
            'translation_language' => ['required', 'string', 'max:128'],
        ]);

        $settings = UserSetting::firstOrNew(['user_id' => $user->id]);
        $settings->openrouter_model = $validated['openrouter_model'];
        $settings->translation_language = $validated['translation_language'];

        if ($request->filled('openrouter_api_key')) {
            $settings->openrouter_api_key = $validated['openrouter_api_key'];
        }

        $settings->user_id = $user->id;
        $settings->save();

        return redirect()->route('settings.edit')->with('status', 'Settings saved.');
    }
}

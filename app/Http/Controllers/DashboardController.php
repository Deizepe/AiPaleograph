<?php

namespace App\Http\Controllers;

use App\Models\ProjectPage;
use App\Services\OpenRouterService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(OpenRouterService $openRouter): View
    {
        $user = auth()->user();
        $projects = $user->projects()->latest()->limit(6)->get();
        $projectsCount = $user->projects()->count();

        $pageImagesCount = ProjectPage::query()
            ->whereHas('project', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        $openrouterCredits = null;
        $settings = $user->settings;
        if ($settings && filled($settings->openrouter_api_key)) {
            $openrouterCredits = $openRouter->fetchCredits($settings->openrouter_api_key);
        }

        return view('app.dashboard', compact(
            'projects',
            'projectsCount',
            'pageImagesCount',
            'openrouterCredits',
        ));
    }
}

<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectPdfController;
use App\Http\Controllers\ProjectPageController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('logout', LogoutController::class)->name('logout');

    Route::resource('projects', ProjectController::class);
    Route::get('projects/{project}/pdf/full', [ProjectPdfController::class, 'full'])->name('projects.pdf.full');
    Route::get('projects/{project}/pdf/original', [ProjectPdfController::class, 'original'])->name('projects.pdf.original');
    Route::get('projects/{project}/pdf/transcribed', [ProjectPdfController::class, 'transcribed'])->name('projects.pdf.transcribed');
    Route::get('projects/{project}/word/full', [ProjectPdfController::class, 'fullWord'])->name('projects.word.full');

    Route::get('projects/{project}/pages-data', [ProjectPageController::class, 'data'])->name('projects.pages.data');
    Route::post('projects/{project}/pages', [ProjectPageController::class, 'store'])->name('projects.pages.store');
    Route::get('projects/{project}/pages/{page}/image', [ProjectPageController::class, 'image'])->name('projects.pages.image');
    Route::patch('projects/{project}/pages/{page}', [ProjectPageController::class, 'update'])->name('projects.pages.update');
    Route::post('projects/{project}/pages/{page}/move-up', [ProjectPageController::class, 'moveUp'])->name('projects.pages.move-up');
    Route::post('projects/{project}/pages/{page}/move-down', [ProjectPageController::class, 'moveDown'])->name('projects.pages.move-down');
    Route::post('projects/{project}/pages/{page}/move-to', [ProjectPageController::class, 'moveTo'])->name('projects.pages.move-to');
    Route::post('projects/{project}/pages/{page}/retranscribe', [ProjectPageController::class, 'retranscribe'])->name('projects.pages.retranscribe');
    Route::delete('projects/{project}/pages/{page}', [ProjectPageController::class, 'destroy'])->name('projects.pages.destroy');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
});

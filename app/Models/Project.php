<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(ProjectPage::class)->orderBy('page_number');
    }

    public function recalculatePageNumbers(): void
    {
        $sorted = $this->pages()->get()->sort(function (ProjectPage $a, ProjectPage $b) {
            return strnatcasecmp($a->original_filename, $b->original_filename);
        })->values();

        foreach ($sorted as $index => $page) {
            $page->update(['page_number' => $index + 1]);
        }
    }
}

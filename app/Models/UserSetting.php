<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    public const DEFAULT_OPENROUTER_MODEL = 'openai/gpt-5.4';

    protected $fillable = [
        'user_id',
        'openrouter_api_key',
        'openrouter_model',
        'translation_language',
    ];

    protected $hidden = [
        'openrouter_api_key',
    ];

    protected function casts(): array
    {
        return [
            'openrouter_api_key' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

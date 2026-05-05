<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_id',
        'page_number',
        'original_filename',
        'object_key',
        'mime_type',
        'original_text',
        'transcribed_text',
        'observations',
        'transcription_status',
        'transcription_error',
        'processing_time_ms',
        'processing_cost_usd',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

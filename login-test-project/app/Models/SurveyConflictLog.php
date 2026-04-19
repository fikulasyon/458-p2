<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyConflictLog extends Model
{
    protected $fillable = [
        'session_id',
        'old_version_id',
        'new_version_id',
        'conflict_type',
        'recovery_strategy',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SurveySession::class, 'session_id');
    }

    public function oldVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'old_version_id');
    }

    public function newVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'new_version_id');
    }
}

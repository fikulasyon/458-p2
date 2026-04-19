<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveySession extends Model
{
    protected $fillable = [
        'survey_id',
        'user_id',
        'started_version_id',
        'current_version_id',
        'current_question_id',
        'status',
        'stable_node_key',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function startedVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'started_version_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'current_version_id');
    }

    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'current_question_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class, 'session_id');
    }

    public function conflictLogs(): HasMany
    {
        return $this->hasMany(SurveyConflictLog::class, 'session_id');
    }
}

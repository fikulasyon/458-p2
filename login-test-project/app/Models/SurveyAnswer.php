<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyAnswer extends Model
{
    protected $fillable = [
        'session_id',
        'question_stable_key',
        'question_id',
        'answer_value',
        'valid_under_version_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SurveySession::class, 'session_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }

    public function validUnderVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'valid_under_version_id');
    }
}

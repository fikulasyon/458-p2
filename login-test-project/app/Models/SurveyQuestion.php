<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyQuestion extends Model
{
    protected $fillable = [
        'survey_version_id',
        'stable_key',
        'title',
        'type',
        'is_entry',
        'order_index',
        'metadata',
    ];

    protected $casts = [
        'is_entry' => 'boolean',
        'metadata' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id');
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(QuestionEdge::class, 'from_question_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(QuestionEdge::class, 'to_question_id');
    }
}

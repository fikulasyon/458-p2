<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionEdge extends Model
{
    protected $fillable = [
        'survey_version_id',
        'from_question_id',
        'to_question_id',
        'condition_type',
        'condition_operator',
        'condition_value',
        'priority',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }

    public function fromQuestion(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'from_question_id');
    }

    public function toQuestion(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'to_question_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyVersion extends Model
{
    protected $fillable = [
        'survey_id',
        'version_number',
        'status',
        'base_version_id',
        'is_active',
        'published_at',
        'schema_meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'schema_meta' => 'array',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_version_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(QuestionEdge::class);
    }
}

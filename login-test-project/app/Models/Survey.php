<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Survey extends Model
{
    protected $fillable = [
        'title',
        'description',
        'created_by',
        'active_version_id',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'active_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SurveyVersion::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(SurveySession::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    protected $fillable = [
        'user_id',
        'event_type',
        'ip',
        'user_agent',
        'risk_score',
        'reasons',
        'meta',
    ];

    protected $casts = [
        'reasons' => 'array',
        'meta' => 'array',
    ];
}
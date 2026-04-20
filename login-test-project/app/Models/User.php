<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'facebook_id',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'challenge_locked_until' => 'datetime',
            'challenge_otp_expires_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    public function createdSurveys(): HasMany
    {
        return $this->hasMany(Survey::class, 'created_by');
    }

    public function surveySessions(): HasMany
    {
        return $this->hasMany(SurveySession::class);
    }

    public function mobileApiTokens(): HasMany
    {
        return $this->hasMany(MobileApiToken::class);
    }

    public function transitionState(string $to, ?int $actorUserId = null, array $meta = []): void
    {
        $from = (string) ($this->account_state ?? 'Active');
        if ($from === $to) {
            return;
        }

        $this->account_state = $to;
        $this->save();

        \App\Models\SecurityEvent::create([
            'user_id' => $this->id,
            'event_type' => 'state_changed',
            'meta' => json_encode(array_merge([
                'from' => $from,
                'to' => $to,
                'actor_user_id' => $actorUserId,
            ], $meta)),
        ]);

        if ($to === 'Suspended') {
            \App\Models\SecurityEvent::create([
                'user_id' => $this->id,
                'event_type' => 'user_suspended',
                'meta' => json_encode([
                    'from' => $from,
                    'actor_user_id' => $actorUserId,
                ]),
            ]);
        }

        if ($from === 'Suspended' && $to !== 'Suspended') {
            \App\Models\SecurityEvent::create([
                'user_id' => $this->id,
                'event_type' => 'user_unsuspended',
                'meta' => json_encode([
                    'to' => $to,
                    'actor_user_id' => $actorUserId,
                ]),
            ]);
        }
    }
}

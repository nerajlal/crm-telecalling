<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Call extends Model
{
    public const ACTIVE = ['initiating', 'queued', 'in-progress', 'unknown'];

    public const TERMINAL = ['completed', 'busy', 'no-answer', 'failed', 'canceled'];

    protected $guarded = ['id'];

    protected $hidden = ['callback_token'];

    protected function casts(): array
    {
        return ['initiated_at' => 'datetime', 'connected_at' => 'datetime', 'ended_at' => 'datetime', 'provider_updated_at' => 'datetime', 'last_synced_at' => 'datetime', 'resolved_at' => 'datetime', 'duration_seconds' => 'integer'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function events()
    {
        return $this->hasMany(CallEvent::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->inCurrentMode()->when(! $user->isOwner(), fn ($q) => $q->where('employee_id', $user->id)->whereHas('lead', fn ($q) => $q->where('assigned_to', $user->id)));
    }

    public static function demoEnabled(): bool
    {
        return config('telephony.driver') === 'demo' && app()->environment('local', 'testing');
    }

    public function scopeInCurrentMode(Builder $q): Builder
    {
        return $q->where('provider', self::demoEnabled() ? 'demo' : 'exotel');
    }

    public function scopeForReporting(Builder $q): Builder
    {
        $q->inCurrentMode();
        if (! self::demoEnabled()) {
            $q->whereNotNull('provider_sid')->whereNotNull('last_synced_at');
        }

        return $q;
    }

    public function isProviderVerified(): bool
    {
        return $this->provider === 'exotel' && $this->provider_sid && $this->last_synced_at;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE) && ! $this->resolved_at;
    }
}

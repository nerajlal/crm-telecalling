<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    public const STAGES = ['New', 'Contacted', 'Follow-up', 'Qualified', 'Converted', 'Lost'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'assigned_to' => 'integer'];
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function calls()
    {
        return $this->hasMany(Call::class);
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->when(! $user->isOwner(), fn ($q) => $q->where('assigned_to', $user->id));
    }
}

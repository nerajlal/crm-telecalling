<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FollowUp extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->when(! $user->isOwner(), fn ($q) => $q->where('employee_id', $user->id)->whereHas('lead', fn ($q) => $q->where('assigned_to', $user->id)));
    }
}

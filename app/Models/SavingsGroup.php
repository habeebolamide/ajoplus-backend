<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsGroup extends Model
{
    protected $fillable = ['creator_id', 'name', 'description', 'contribution_amount_kobo', 'frequency', 'max_members', 'start_date', 'invite_code', 'current_cycle', 'status'];

    protected function casts(): array
    {
        return ['contribution_amount_kobo' => 'integer', 'max_members' => 'integer', 'current_cycle' => 'integer', 'start_date' => 'date:Y-m-d'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class, 'group_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'group_id');
    }
}

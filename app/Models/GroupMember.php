<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMember extends Model
{
    protected $fillable = ['group_id', 'user_id', 'payout_position', 'joined_at'];

    protected function casts(): array
    {
        return ['payout_position' => 'integer', 'joined_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SavingsGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

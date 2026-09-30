<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = ['group_id', 'member_id', 'cycle', 'amount_kobo', 'scheduled_for', 'status', 'completed_at'];

    protected function casts(): array
    {
        return ['cycle' => 'integer', 'amount_kobo' => 'integer', 'scheduled_for' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'member_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contribution extends Model
{
    protected $fillable = ['group_id', 'member_id', 'cycle', 'amount_kobo', 'status', 'payment_reference', 'paid_at'];

    protected function casts(): array
    {
        return ['cycle' => 'integer', 'amount_kobo' => 'integer', 'paid_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'member_id');
    }
}

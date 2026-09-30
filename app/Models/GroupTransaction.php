<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupTransaction extends Model
{
    protected $table = 'transactions';

    protected $fillable = ['group_id', 'member_id', 'type', 'amount_kobo', 'status', 'reference'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SavingsGroup::class, 'group_id');
    }
}

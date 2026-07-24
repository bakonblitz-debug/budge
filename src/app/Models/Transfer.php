<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A linked pair of transactions representing money moved between the user's own
 * accounts. The two legs (from = money out, to = money in) each store this
 * transfer's id in `transactions.transfer_id`.
 */
class Transfer extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'from_transaction_id', 'to_transaction_id', 'detected_via', 'note',
    ];

    public function fromTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'from_transaction_id');
    }

    public function toTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'to_transaction_id');
    }

    /** Both legs, linked back via transactions.transfer_id. */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

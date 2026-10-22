<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payment records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Payment records are immutable.'));
    }
}

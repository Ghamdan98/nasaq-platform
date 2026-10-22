<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvanceTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Posted ledger entries are immutable; create a reversal.'));
        static::deleting(fn () => throw new \LogicException('Posted ledger entries cannot be deleted.'));
    }
}

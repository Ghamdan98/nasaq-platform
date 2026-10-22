<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaborRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'article_number',
        'title',
        'category',
        'description',
        'effective_from',
        'effective_to',
        'source_name',
        'source_url',
        'version',
        'is_active',
    ];
}

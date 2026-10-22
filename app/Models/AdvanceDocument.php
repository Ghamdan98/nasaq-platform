<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvanceDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];
}

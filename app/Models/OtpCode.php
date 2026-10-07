<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'code', 'expires_at'])]
class OtpCode extends Model
{
    protected $casts = [
        'expires_at' => 'datetime',
    ];
}

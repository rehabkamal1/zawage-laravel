<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'type',
    'views_allowed',
    'views_used',
    'expires_at',
    'status',
])]
class Subscription extends Model
{
    use HasFactory;

    protected $casts = [
        'expires_at' => 'datetime',
        'views_allowed' => 'integer',
        'views_used' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }
}

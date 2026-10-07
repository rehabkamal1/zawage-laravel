<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'contacted_user_id',
    'subscription_id',
])]
class Contact extends Model
{
    use HasFactory;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contactedUser()
    {
        return $this->belongsTo(User::class, 'contacted_user_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}

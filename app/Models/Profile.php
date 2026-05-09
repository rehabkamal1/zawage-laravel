<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'full_name',
    'nickname',
    'phone',
    'dob',
    'marital_status',
    'skin_tone',
    'weight',
    'height',
    'governorate',
    'area',
    'address',
    'education',
    'job',
    'income',
    'accommodation',
    'prayer',
    'hijab',
    'has_children',
    'custody',
    'children_count',
    'guardian_name',
    'guardian_phone',
    'relation',
    'move_other_gov',
    'family_house',
    'dowry_status',
    'accept_polygamy',
    'diseases',
    'smoking',
    'bio',
    'req_governorate',
    'req_age_min',
    'req_age_max',
    'req_marital_status',
    'req_hijab',
    'req_weight',
    'req_height',
    'req_prayer',
    'req_move_other_gov',
    'req_has_children',
    'req_children_count',
    'req_guardian_phone',
    'req_job',
    'req_education',
    'req_smoking',
    'req_accept_polygamy',
    'partner_status',
    'partner_specs'
])]
class Profile extends Model
{
    use HasFactory;

    protected $casts = [
        'has_children' => 'boolean',
        'move_other_gov' => 'boolean',
        'family_house' => 'boolean',
        'accept_polygamy' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

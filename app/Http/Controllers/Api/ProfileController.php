<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Display the authenticated user's profile.
     */
    public function show(Request $request)
    {
        return response()->json([
            'profile' => $request->user()->profile()->firstOrCreate(['user_id' => $request->user()->id]),
        ]);
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        
        // We'll accept all fields sent since we have fillable set up
        $data = $request->all();

        // Basic boolean conversion if sent as strings from frontend
        $booleans = ['has_children', 'move_other_gov', 'family_house', 'accept_polygamy'];
        foreach ($booleans as $bool) {
            if (isset($data[$bool])) {
                $data[$bool] = $data[$bool] === 'yes' || $data[$bool] === true || $data[$bool] === 1;
            }
        }

        $profile = $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            $data
        );

        return response()->json([
            'message' => 'تم تحديث الاستمارة بنجاح.',
            'profile' => $profile,
        ]);
    }
}

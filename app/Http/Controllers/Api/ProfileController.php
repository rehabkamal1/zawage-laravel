<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProfileRequest;

class ProfileController extends Controller
{
    /**
     * Display the authenticated user's profile.
     */
    public function show(Request $request)
    {
        if (!$request->user()) {
            return response()->json(['message' => 'يجب تسجيل الدخول أولاً'], 401);
        }
        return response()->json([
            'profile' => $request->user()->profile()->firstOrCreate(
                ['user_id' => $request->user()->id],
                [
                    'full_name'     => $request->user()->name,
                    'phone'         => '',
                    'dob'           => '1990-01-01',
                    'governorate'   => '',
                    'area'          => '',
                    'address'       => '',
                    'education'     => '',
                    'job'           => '',
                    'income'        => '',
                    'accommodation' => '',
                    'prayer'        => '',
                    'hijab'         => '',
                    'smoking'       => '',
                ]
            ),
        ]);
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(StoreProfileRequest $request)
    {
        if (!$request->user()) {
            return response()->json(['message' => 'يجب تسجيل الدخول أولاً'], 401);
        }
        $user = $request->user();
        
        // Enforce subscription for male users before filling/updating the profile/form
        if ($user->gender === 'male') {
            $hasActiveSubscription = $user->subscriptions()
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists();
            if (!$hasActiveSubscription) {
                return response()->json([
                    'message' => 'عذراً، يجب عليك الاشتراك وتفعيل حسابك أولاً بالدفع لتتمكن من ملء استمارة الزواج.',
                    'requires_subscription' => true
                ], 403);
            }
        }

        // We'll accept all fields sent since we have fillable set up
        $data = $request->all();

        // Ensure all database NOT NULL columns have at least an empty string or default value if they are null/missing
        $nonNullableDefaults = [
            'full_name'     => $user->name,
            'phone'         => $user->phone ?? '',
            'dob'           => '1990-01-01',
            'governorate'   => '',
            'area'          => '',
            'address'       => '',
            'education'     => '',
            'job'           => '',
            'income'        => '',
            'accommodation' => '',
            'prayer'        => '',
            'hijab'         => '',
            'smoking'       => '',
        ];
        foreach ($nonNullableDefaults as $field => $defaultValue) {
            if (!isset($data[$field]) || is_null($data[$field])) {
                $data[$field] = $defaultValue;
            }
        }

        // Basic boolean conversion if sent as strings from frontend
        $booleans = ['has_children', 'move_other_gov', 'family_house', 'accept_polygamy'];
        foreach ($booleans as $bool) {
            if (isset($data[$bool])) {
                $data[$bool] = $data[$bool] === 'yes' || $data[$bool] === true || $data[$bool] === 1;
            }
        }

        // Update user phone number in users table as well to keep them in sync
        if (isset($data['phone'])) {
            $user->update(['phone' => $data['phone']]);
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

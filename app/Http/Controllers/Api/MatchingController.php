<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class MatchingController extends Controller
{
    /**
     * Find potential matches for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user->gender) {
            return response()->json([
                'message' => 'يرجى إكمال بياناتك أولاً لرؤية الشركاء المتاحين.',
                'matches' => [],
            ]);
        }

        $oppositeGender = $user->gender === 'male' ? 'female' : 'male';

        $query = User::where('id', '!=', $user->id)
            ->where('gender', $oppositeGender)
            ->where('is_banned', false)
            ->where('role', 'user')
            ->with('profile');

        // Apply Governorate Filter
        if ($request->has('governorate') && $request->governorate != 'الكل') {
            $query->whereHas('profile', function($q) use ($request) {
                $q->where('governorate', $request->governorate);
            });
        }

        // Apply Marital Status Filter
        if ($request->has('marital_status') && $request->marital_status != 'الكل') {
            $query->whereHas('profile', function($q) use ($request) {
                $q->where('marital_status', $request->marital_status);
            });
        }

        // Apply Age Filter
        if ($request->has('age_range') && $request->age_range != 'الكل') {
            $range = $request->age_range;
            $min = 18;
            $max = 100;

            if ($range == '18-25') { $min = 18; $max = 25; }
            elseif ($range == '26-35') { $min = 26; $max = 35; }
            elseif ($range == '36+') { $min = 36; $max = 100; }

            $query->whereHas('profile', function($q) use ($min, $max) {
                $q->whereBetween('dob', [
                    now()->subYears($max + 1)->endOfDay(),
                    now()->subYears($min)->startOfDay()
                ]);
            });
        }

        $matches = $query->get();

        return response()->json([
            'matches' => $matches,
        ]);
    }
}

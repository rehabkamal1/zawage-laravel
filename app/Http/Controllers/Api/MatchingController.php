<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
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

        $potentialMatches = $query->get();

        $myProfile = $user->profile;

        // Calculate compatibility for each match
        $matches = $potentialMatches->map(function ($match) use ($myProfile) {
            $theirProfile = $match->profile;
            $percentage = $this->calculateCompatibility($myProfile, $theirProfile);

            $matchData = $match->toArray();
            $matchData['match_percentage'] = $percentage;

            return $matchData;
        });

        // Sort by match_percentage descending
        $matches = $matches->sortByDesc('match_percentage')->values();

        return response()->json([
            'matches' => $matches,
        ]);
    }

    /**
     * Calculate compatibility percentage between two profiles.
     */
    private function calculateCompatibility($myProfile, $theirProfile): int
    {
        if (!$myProfile || !$theirProfile) {
            return 0;
        }

        $totalCriteria = 0;
        $matchedCriteria = 0;

        // 1. Governorate match
        if ($myProfile->req_governorate) {
            $totalCriteria++;
            if (mb_strtolower($myProfile->req_governorate) === mb_strtolower($theirProfile->governorate ?? '')) {
                $matchedCriteria++;
            }
        }

        // 2. Age range match
        if ($myProfile->req_age_min || $myProfile->req_age_max) {
            $totalCriteria++;
            if ($theirProfile->dob) {
                $theirAge = Carbon::parse($theirProfile->dob)->age;
                $minAge = $myProfile->req_age_min ?? 0;
                $maxAge = $myProfile->req_age_max ?? 100;
                if ($theirAge >= $minAge && $theirAge <= $maxAge) {
                    $matchedCriteria++;
                }
            }
        }

        // 3. Marital status match
        if ($myProfile->req_marital_status) {
            $totalCriteria++;
            if (mb_strtolower($myProfile->req_marital_status) === mb_strtolower($theirProfile->marital_status ?? '')) {
                $matchedCriteria++;
            }
        }

        // 4. Hijab match
        if ($myProfile->req_hijab) {
            $totalCriteria++;
            if (mb_strtolower($myProfile->req_hijab) === mb_strtolower($theirProfile->hijab ?? '')) {
                $matchedCriteria++;
            }
        }

        // 5. Prayer match
        if ($myProfile->req_prayer) {
            $totalCriteria++;
            if (mb_strtolower($myProfile->req_prayer) === mb_strtolower($theirProfile->prayer ?? '')) {
                $matchedCriteria++;
            }
        }

        // 6. Smoking match
        if ($myProfile->req_smoking) {
            $totalCriteria++;
            if (mb_strtolower($myProfile->req_smoking) === mb_strtolower($theirProfile->smoking ?? '')) {
                $matchedCriteria++;
            }
        }

        // 7. Has children match
        if ($myProfile->req_has_children) {
            $totalCriteria++;
            $reqChildren = mb_strtolower($myProfile->req_has_children);
            $hasChildren = $theirProfile->has_children;
            if (($reqChildren === 'لا' || $reqChildren === 'no') && !$hasChildren) {
                $matchedCriteria++;
            } elseif (($reqChildren === 'نعم' || $reqChildren === 'yes') && $hasChildren) {
                $matchedCriteria++;
            }
        }

        // 8. Education match
        if ($myProfile->req_education) {
            $totalCriteria++;
            $reqEdu = mb_strtolower($myProfile->req_education);
            if ($reqEdu === 'أي' || $reqEdu === 'لا يهم' || $reqEdu === 'any') {
                $matchedCriteria++;
            } elseif (mb_strpos(mb_strtolower($theirProfile->education ?? ''), $reqEdu) !== false) {
                $matchedCriteria++;
            }
        }

        // 9. Job match
        if ($myProfile->req_job) {
            $totalCriteria++;
            $reqJob = mb_strtolower($myProfile->req_job);
            if ($reqJob === 'أي وظيفة' || $reqJob === 'لا يهم' || $reqJob === 'any') {
                $matchedCriteria++;
            } elseif (mb_strpos(mb_strtolower($theirProfile->job ?? ''), $reqJob) !== false) {
                $matchedCriteria++;
            }
        }

        // 10. Accept polygamy match
        if ($myProfile->req_accept_polygamy) {
            $totalCriteria++;
            $reqPoly = mb_strtolower($myProfile->req_accept_polygamy);
            $acceptPoly = $theirProfile->accept_polygamy;
            if (($reqPoly === 'لا' || $reqPoly === 'no') && !$acceptPoly) {
                $matchedCriteria++;
            } elseif (($reqPoly === 'نعم' || $reqPoly === 'yes') && $acceptPoly) {
                $matchedCriteria++;
            }
        }

        if ($totalCriteria === 0) {
            return 50; // Default if no requirements specified
        }

        return (int) round(($matchedCriteria / $totalCriteria) * 100);
    }
}

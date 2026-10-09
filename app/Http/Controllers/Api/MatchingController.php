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

        // إلزام العريس بملء استمارته أولاً لرؤية العرايس
        $myProfile = $user->profile;
        $hasFilledForm = $myProfile 
            && !empty(trim($myProfile->governorate ?? '')) 
            && !empty(trim($myProfile->marital_status ?? ''));

        if ($user->gender === 'male' && !$hasFilledForm) {
            return response()->json([
                'message' => 'عذراً، يجب عليك ملء استمارتك أولاً لتتمكن من رؤية العرايس.',
                'requires_form' => true,
                'matches' => [],
            ], 403);
        }

        $oppositeGender = $user->gender === 'male' ? 'female' : 'male';

        $query = User::where('id', '!=', $user->id)
            ->where('gender', $oppositeGender)
            ->where('is_banned', false)
            ->where('role', 'user')
            ->whereHas('profile', function($q) use ($oppositeGender) {
                $q->whereNotNull('governorate')->where('governorate', '!=', '')
                  ->whereNotNull('marital_status')->where('marital_status', '!=', '')
                  ->whereNotNull('dob')->where('dob', '!=', '1990-01-01');
                if ($oppositeGender === 'female') {
                    $q->whereNotNull('guardian_phone')->where('guardian_phone', '!=', '');
                }
            })
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

        // Find unlocked user IDs for authenticated user
        $unlockedUserIds = \App\Models\Contact::where('user_id', $user->id)
            ->pluck('contacted_user_id')
            ->toArray();

        $freeContactsCount = \App\Models\Contact::where('user_id', $user->id)
            ->whereNull('subscription_id')
            ->count();

        // Calculate compatibility for each match
        $matches = $potentialMatches->map(function ($match) use ($myProfile, $user, $unlockedUserIds) {
            $theirProfile = $match->profile;
            $percentage = $this->calculateCompatibility($myProfile, $theirProfile);

            $matchData = $match->toArray();
            $matchData['match_percentage'] = $percentage;
            $matchData['compatibility_score'] = $percentage;

            if ($user->gender === 'male') {
                $isUnlocked = in_array($match->id, $unlockedUserIds);
                $matchData['is_unlocked'] = $isUnlocked;

                if ($isUnlocked) {
                    $guardianPhone = $theirProfile->guardian_phone ?? '';
                    $matchData['phone'] = $guardianPhone;
                    if (isset($matchData['profile'])) {
                        $matchData['profile']['phone'] = $guardianPhone;
                    }
                    $matchData['whatsapp_link'] = $guardianPhone
                        ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                        : null;
                } else {
                    $matchData['phone'] = 'مخفي - اضغط فك القفل للتواصل';
                    if (isset($matchData['profile'])) {
                        $matchData['profile']['phone'] = 'مخفي - اضغط فك القفل للتواصل';
                    }
                    $matchData['whatsapp_link'] = null;
                }
            } else {
                $matchData['is_unlocked'] = true;
                $phone = $match->phone ?? '';
                $matchData['phone'] = $phone;
                $matchData['whatsapp_link'] = $phone
                    ? 'https://wa.me/' . preg_replace('/\D/', '', $phone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                    : null;
            }

            return $matchData;
        });

        // Sort by match_percentage descending (highest compatibility score first)
        $matches = $matches->sortByDesc('match_percentage')->values();

        return response()->json([
            'matches' => $matches,
            'total_matches' => $matches->count(),
            'free_views_used' => $freeContactsCount ?? 0,
            'free_views_allowed' => 3,
            'free_views_remaining' => max(0, 3 - ($freeContactsCount ?? 0)),
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

        // 11. Qaima status match
        if ($myProfile->qaima_status && $theirProfile->qaima_status) {
            $totalCriteria++;
            $myQaima = mb_strtolower($myProfile->qaima_status);
            $theirQaima = mb_strtolower($theirProfile->qaima_status);
            if ($myQaima === $theirQaima || $myQaima === 'حسب الاتفاق' || $theirQaima === 'حسب الاتفاق') {
                $matchedCriteria++;
            }
        }

        if ($totalCriteria === 0) {
            return 50; // Default if no requirements specified
        }

        return (int) round(($matchedCriteria / $totalCriteria) * 100);
    }

    /**
     * Unlock and fetch contact details for a partner (Bride/Groom).
     * Deducts 1 view/attempt from active subscription if male viewer and first time contacting.
     */
    public function contact(Request $request, $id)
    {
        $user = $request->user();
        $targetUser = User::where('id', $id)->where('is_banned', false)->where('role', 'user')->first();

        if (!$targetUser) {
            return response()->json(['message' => 'الملف الشخصي المستهدف غير موجود أو تم حظره.'], 404);
        }

        if ($targetUser->gender === $user->gender) {
            return response()->json(['message' => 'لا يمكنك التواصل مع ملف شخصي من نفس جنسك.'], 400);
        }

        $theirProfile = $targetUser->profile;
        if (!$theirProfile) {
            return response()->json(['message' => 'الملف الشخصي المستهدف لم يكمل استمارته بعد.'], 404);
        }

        $myProfile = $user->profile;
        $hasFilledForm = $myProfile 
            && !empty(trim($myProfile->governorate ?? '')) 
            && !empty(trim($myProfile->marital_status ?? ''));

        if ($user->gender === 'male' && !$hasFilledForm) {
            return response()->json([
                'message' => 'عذراً، يجب عليك ملء استمارتك أولاً لتتمكن من التواصل مع العروس.',
                'requires_form' => true,
            ], 403);
        }

        if ($user->gender === 'male') {
            // 1. Check if already contacted/unlocked
            $existingContact = \App\Models\Contact::where('user_id', $user->id)
                ->where('contacted_user_id', $targetUser->id)
                ->first();

            $guardianPhone = $theirProfile->guardian_phone ?? '';

            if ($existingContact) {
                return response()->json([
                    'message' => 'تم فك القفل مسبقاً لهذا الملف الشخصي.',
                    'is_unlocked' => true,
                    'phone' => $guardianPhone,
                    'whatsapp_link' => $guardianPhone
                        ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                        : null,
                    'views_used' => 0,
                    'views_allowed' => 3,
                ]);
            }

            // 2. Count free contacts used by this user (where subscription_id is null)
            $freeContactsCount = \App\Models\Contact::where('user_id', $user->id)
                ->whereNull('subscription_id')
                ->count();

            // Find active subscription if any
            $activeSub = $user->subscriptions()
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->first();

            // Case A: User still has free attempts left (under 3)
            if ($freeContactsCount < 3) {
                \App\Models\Contact::create([
                    'user_id' => $user->id,
                    'contacted_user_id' => $targetUser->id,
                    'subscription_id' => null,
                ]);

                $newUsed = $freeContactsCount + 1;
                $remainingFree = 3 - $newUsed;

                return response()->json([
                    'message' => 'تم فك القفل بنجاح من رصيدك المجاني. المتبقي: ' . $remainingFree . ' من 3 محاولات مجانية.',
                    'is_unlocked' => true,
                    'is_free_attempt' => true,
                    'phone' => $guardianPhone,
                    'whatsapp_link' => $guardianPhone
                        ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                        : null,
                    'views_used' => $newUsed,
                    'views_allowed' => 3,
                    'remaining' => $remainingFree,
                ]);
            }

            // Case B: Free attempts exhausted ($freeContactsCount >= 3)
            if (!$activeSub) {
                return response()->json([
                    'message' => 'لقد استنفدت محاولاتك المجانية (3 استمارات). يرجى الاشتراك في إحدى باقاتنا (اليومية، الأسبوعية، أو الشهرية) للتمكن من فك قفل استمارات جديدة والتواصل.',
                    'requires_subscription' => true,
                    'free_attempts_used' => 3,
                    'free_attempts_allowed' => 3,
                ], 403);
            }

            // Check if subscription views exhausted
            if ($activeSub->views_used >= $activeSub->views_allowed) {
                return response()->json([
                    'message' => 'عذراً، لقد استنفدت جميع محاولات التواصل المتاحة في باقتك الحالية. يرجى تجديد الاشتراك للتمكن من التواصل مع المزيد.',
                    'limit_reached' => true,
                    'views_used' => $activeSub->views_used,
                    'views_allowed' => $activeSub->views_allowed,
                ], 403);
            }

            // Deduct from active subscription
            \App\Models\Contact::create([
                'user_id' => $user->id,
                'contacted_user_id' => $targetUser->id,
                'subscription_id' => $activeSub->id,
            ]);

            $activeSub->increment('views_used');

            return response()->json([
                'message' => 'تم فك القفل بنجاح وخصم محاولة واحدة من رصيد باقتك.',
                'is_unlocked' => true,
                'is_free_attempt' => false,
                'phone' => $guardianPhone,
                'whatsapp_link' => $guardianPhone
                    ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                    : null,
                'views_used' => $activeSub->views_used,
                'views_allowed' => $activeSub->views_allowed,
                'remaining' => $activeSub->views_allowed - $activeSub->views_used,
            ]);

        } else {
            // Female users have unlimited free access to contact grooms
            $phone = $targetUser->phone ?? '';
            return response()->json([
                'message' => 'تفاصيل التواصل للملف الشخصي.',
                'is_unlocked' => true,
                'phone' => $phone,
                'whatsapp_link' => $phone
                    ? 'https://wa.me/' . preg_replace('/\D/', '', $phone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                    : null,
                'views_used' => 0,
                'views_allowed' => -1, // Unlimited
            ]);
        }
    }
}

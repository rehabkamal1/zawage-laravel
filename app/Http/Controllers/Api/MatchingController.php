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

        // Find active subscription and unlocked user IDs if user is male
        $activeSub = null;
        $unlockedUserIds = [];
        if ($user->gender === 'male') {
            $activeSub = $user->subscriptions()
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->first();

            if (!$activeSub) {
                return response()->json([
                    'message' => 'عذراً، يجب عليك تفعيل اشتراك نشط أولاً لتتمكن من رؤية الشركاء ونسب التوافق.',
                    'requires_subscription' => true,
                    'matches' => [],
                ], 403);
            }

            $unlockedUserIds = \App\Models\Contact::where('user_id', $user->id)
                ->where('subscription_id', $activeSub->id)
                ->pluck('contacted_user_id')
                ->toArray();
        }

        // Calculate compatibility for each match
        $matches = $potentialMatches->map(function ($match) use ($myProfile, $user, $unlockedUserIds) {
            $theirProfile = $match->profile;
            $percentage = $this->calculateCompatibility($myProfile, $theirProfile);

            $matchData = $match->toArray();
            $matchData['match_percentage'] = $percentage;
            $matchData['compatibility_score'] = $percentage; // Return compatibility_score in results to match frontend

            if ($user->gender === 'male') {
                $isUnlocked = in_array($match->id, $unlockedUserIds);
                $matchData['is_unlocked'] = $isUnlocked;

                if ($isUnlocked) {
                    // Show guardian's phone number instead of bride's personal number
                    $guardianPhone = $theirProfile->guardian_phone ?? '';
                    $matchData['phone'] = $guardianPhone;
                    if (isset($matchData['profile'])) {
                        $matchData['profile']['phone'] = $guardianPhone;
                    }
                    $matchData['whatsapp_link'] = $guardianPhone
                        ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                        : null;
                } else {
                    // Mask phone numbers until they unlock it
                    $matchData['phone'] = 'مخفي - تواصل لفك القفل';
                    if (isset($matchData['profile'])) {
                        $matchData['profile']['phone'] = 'مخفي - تواصل لفك القفل';
                    }
                    $matchData['whatsapp_link'] = null;
                }
            } else {
                // Females can see grooms' phone numbers directly
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

        // Limit the results based on the subscription type for male users
        if ($user->gender === 'male' && $activeSub) {
            if ($activeSub->type === 'daily') {
                $limit = 10;
            } elseif ($activeSub->type === 'weekly') {
                $limit = 20;
            } else {
                $limit = 50;
            }
            $matches = $matches->take($limit)->values();
        }

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

        // Check if the viewer is male
        if ($user->gender === 'male') {
            // Find active subscription
            $activeSub = $user->subscriptions()
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->first();

            if (!$activeSub) {
                return response()->json([
                    'message' => 'عذراً، يجب عليك تفعيل اشتراك نشط أولاً لتتمكن من فك قفل استمارات التواصل وتفاصيل الاتصال.',
                    'requires_subscription' => true
                ], 403);
            }

            // Check if already contacted/unlocked under this active subscription
            $existingContact = \App\Models\Contact::where('user_id', $user->id)
                ->where('contacted_user_id', $targetUser->id)
                ->where('subscription_id', $activeSub->id)
                ->first();

            if ($existingContact) {
                $guardianPhone = $theirProfile->guardian_phone ?? '';
                return response()->json([
                    'message' => 'تم فك القفل مسبقاً لهذا الملف الشخصي.',
                    'is_unlocked' => true,
                    'phone' => $guardianPhone,
                    'whatsapp_link' => $guardianPhone
                        ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                        : null,
                    'views_used' => $activeSub->views_used,
                    'views_allowed' => $activeSub->views_allowed,
                ]);
            }

            // Deduct 1 attempt: Check if views left
            if ($activeSub->views_used >= $activeSub->views_allowed) {
                return response()->json([
                    'message' => 'عذراً، لقد استنفدت جميع محاولات التواصل المتاحة في باقتك الحالية. يرجى تجديد الاشتراك للتمكن من التواصل مع المزيد.',
                    'limit_reached' => true,
                    'views_used' => $activeSub->views_used,
                    'views_allowed' => $activeSub->views_allowed,
                ], 403);
            }

            // Record contact click and increment used attempts
            \App\Models\Contact::create([
                'user_id' => $user->id,
                'contacted_user_id' => $targetUser->id,
                'subscription_id' => $activeSub->id,
            ]);

            $activeSub->increment('views_used');

            $guardianPhone = $theirProfile->guardian_phone ?? '';

            return response()->json([
                'message' => 'تم فك القفل وتواصلك مع العروس بنجاح. تم خصم محاولة واحدة.',
                'is_unlocked' => true,
                'phone' => $guardianPhone,
                'whatsapp_link' => $guardianPhone
                    ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                    : null,
                'views_used' => $activeSub->views_used,
                'views_allowed' => $activeSub->views_allowed,
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /**
     * Display a listing of the user's favorites.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $favoritesQuery = Favorite::where('user_id', $user->id)
            ->with('favoriteUser.profile')
            ->get()
            ->pluck('favoriteUser');

        $activeSub = null;
        $unlockedUserIds = [];
        /* [TEMPORARILY COMMENTED - SUBSCRIPTION DISABLED]
        if ($user->gender === 'male') {
            $activeSub = $user->subscriptions()
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->first();

            if ($activeSub) {
                $unlockedUserIds = \App\Models\Contact::where('user_id', $user->id)
                    ->where('subscription_id', $activeSub->id)
                    ->pluck('contacted_user_id')
                    ->toArray();
            }
        }
        */

        $favorites = $favoritesQuery->map(function ($favUser) use ($user, $unlockedUserIds) {
            if (!$favUser) return null;
            $favUserData = $favUser->toArray();
            
            /* [TEMPORARILY COMMENTED - SUBSCRIPTION DISABLED]
            if ($user->gender === 'male') {
                $isUnlocked = in_array($favUser->id, $unlockedUserIds);
                $favUserData['is_unlocked'] = $isUnlocked;
                
                if ($isUnlocked) {
                    $guardianPhone = $favUser->profile->guardian_phone ?? '';
                    $favUserData['phone'] = $guardianPhone;
                    if (isset($favUserData['profile'])) {
                        $favUserData['profile']['phone'] = $guardianPhone;
                    }
                    $favUserData['whatsapp_link'] = $guardianPhone
                        ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                        : null;
                } else {
                    $favUserData['phone'] = 'مخفي - تواصل لفك القفل';
                    if (isset($favUserData['profile'])) {
                        $favUserData['profile']['phone'] = 'مخفي - تواصل لفك القفل';
                    }
                    $favUserData['whatsapp_link'] = null;
                }
            } else {
                $favUserData['is_unlocked'] = true;
                $phone = $favUser->phone ?? '';
                $favUserData['phone'] = $phone;
                $favUserData['whatsapp_link'] = $phone
                    ? 'https://wa.me/' . preg_replace('/\D/', '', $phone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                    : null;
            }
            */

            // Free access for all users in favorites
            $favUserData['is_unlocked'] = true;
            if ($user->gender === 'male') {
                $guardianPhone = $favUser->profile->guardian_phone ?? '';
                $favUserData['phone'] = $guardianPhone;
                if (isset($favUserData['profile'])) {
                    $favUserData['profile']['phone'] = $guardianPhone;
                }
                $favUserData['whatsapp_link'] = $guardianPhone
                    ? 'https://wa.me/' . preg_replace('/\D/', '', $guardianPhone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                    : null;
            } else {
                $phone = $favUser->phone ?? '';
                $favUserData['phone'] = $phone;
                $favUserData['whatsapp_link'] = $phone
                    ? 'https://wa.me/' . preg_replace('/\D/', '', $phone) . '?text=' . urlencode('السلام عليكم، لقد رأيت ملفك الشخصي على منصة نصفي الآخر وأريد التواصل معك.')
                    : null;
            }
            return $favUserData;
        })->filter()->values();

        return response()->json([
            'favorites' => $favorites
        ]);
    }

    /**
     * Toggle a favorite status for a user.
     */
    public function toggle(Request $request)
    {
        $request->validate([
            'favorite_user_id' => 'required|exists:users,id'
        ]);

        $userId = $request->user()->id;
        $favoriteUserId = $request->favorite_user_id;

        if ($userId == $favoriteUserId) {
            return response()->json(['message' => 'You cannot favorite yourself.'], 400);
        }

        $existing = Favorite::where('user_id', $userId)
            ->where('favorite_user_id', $favoriteUserId)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['message' => 'تم إزالة العضو من المفضلات.', 'is_favorite' => false]);
        } else {
            Favorite::create([
                'user_id' => $userId,
                'favorite_user_id' => $favoriteUserId
            ]);
            return response()->json(['message' => 'تم إضافة العضو إلى المفضلات.', 'is_favorite' => true]);
        }
    }
}

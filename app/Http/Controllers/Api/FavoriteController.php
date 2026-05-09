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
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with('favoriteUser.profile')
            ->get()
            ->pluck('favoriteUser');

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

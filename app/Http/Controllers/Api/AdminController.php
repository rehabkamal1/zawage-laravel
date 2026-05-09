<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * List all users with profiles.
     */
    public function indexUsers()
    {
        return response()->json([
            'users' => User::with('profile')->get(),
        ]);
    }

    /**
     * Update user details.
     */
    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'sometimes|string|in:user,admin',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user,
        ]);
    }

    /**
     * Delete a user.
     */
    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Toggle ban status for a user.
     */
    public function toggleBan($id)
    {
        $user = User::findOrFail($id);
        $user->is_banned = !$user->is_banned;
        $user->save();

        $status = $user->is_banned ? 'banned' : 'unbanned';

        return response()->json([
            'message' => "User has been $status successfully.",
            'user' => $user,
        ]);
    }

    /**
     * Get platform statistics.
     */
    public function stats()
    {
        $totalUsers = User::where('role', 'user')->count();
        $males      = User::where('role', 'user')->where('gender', 'male')->count();
        $females    = User::where('role', 'user')->where('gender', 'female')->count();
        $banned     = User::where('is_banned', true)->count();

        $pendingReports = \App\Models\Report::where('status', 'pending')->count();

        $recentUsers = User::where('role', 'user')
            ->with('profile')
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($u) {
                return [
                    'id'         => $u->id,
                    'name'       => $u->name,
                    'gender'     => $u->gender,
                    'email'      => $u->email,
                    'is_banned'  => $u->is_banned,
                    'created_at' => $u->created_at,
                    'nickname'   => $u->profile->nickname ?? null,
                ];
            });

        return response()->json([
            'total_users'     => $totalUsers,
            'males'           => $males,
            'females'         => $females,
            'banned_users'    => $banned,
            'pending_reports' => $pendingReports,
            'recent_users'    => $recentUsers,
        ]);
    }
}

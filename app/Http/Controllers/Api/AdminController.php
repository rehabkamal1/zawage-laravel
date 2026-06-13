<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Carbon\Carbon;

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
     * Query params:
     *   period = 'all' | 'month' | 'custom'
     *   year   = (required when period=custom)
     *   month  = (required when period=custom, 1-12)
     */
    public function stats(Request $request)
    {
        $totalUsers = User::where('role', 'user')->count();
        $males      = User::where('role', 'user')->where('gender', 'male')->count();
        $females    = User::where('role', 'user')->where('gender', 'female')->count();
        $banned     = User::where('is_banned', true)->count();

        $pendingReports = \App\Models\Report::where('status', 'pending')->count();

        // --- Last 5 registered forms ---
        $recentUsers = User::where('role', 'user')
            ->with('profile')
            ->latest()
            ->take(5)
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

        // --- Subscription counts per plan type with date filter ---
        $period = $request->query('period', 'month'); // 'all' | 'month' | 'custom'
        $subQuery = Subscription::query();

        if ($period === 'month') {
            $subQuery->whereYear('created_at', Carbon::now()->year)
                     ->whereMonth('created_at', Carbon::now()->month);
        } elseif ($period === 'custom') {
            $year  = (int) $request->query('year', Carbon::now()->year);
            $month = (int) $request->query('month', Carbon::now()->month);
            $subQuery->whereYear('created_at', $year)
                     ->whereMonth('created_at', $month);
        }
        // 'all' => no date filter

        $dailySubs   = (clone $subQuery)->where('type', 'daily')->count();
        $weeklySubs  = (clone $subQuery)->where('type', 'weekly')->count();
        $monthlySubs = (clone $subQuery)->where('type', 'monthly')->count();

        return response()->json([
            'total_users'      => $totalUsers,
            'males'            => $males,
            'females'          => $females,
            'banned_users'     => $banned,
            'pending_reports'  => $pendingReports,
            'recent_users'     => $recentUsers,
            'subscriptions'    => [
                'daily'   => $dailySubs,
                'weekly'  => $weeklySubs,
                'monthly' => $monthlySubs,
            ],
            'period'           => $period,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Payment;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AdminController extends Controller
{
    /**
     * List all users with profiles and subscriptions.
     */
    public function indexUsers()
    {
        $users = User::with(['profile', 'subscriptions'])->get()->map(function ($u) {
            $activeSub = $u->subscriptions ? $u->subscriptions->where('status', 'active')->first() : null;
            $u->active_subscription = $activeSub ? [
                'type' => $activeSub->type,
                'expires_at' => $activeSub->expires_at,
                'views_allowed' => $activeSub->views_allowed,
                'views_used' => $activeSub->views_used,
            ] : null;
            return $u;
        });

        return response()->json([
            'users' => $users,
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
            'role' => 'sometimes|string|in:user,admin,super_admin',
        ]);

        if (isset($validated['role']) && $request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'فقط السوبر أدمن يمكنه تعديل الصلاحيات.'], 403);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user,
        ]);
    }

    /**
     * Change user role (Super Admin only).
     */
    public function changeRole(Request $request, $id)
    {
        $targetUser = User::findOrFail($id);

        if ($targetUser->role === 'super_admin' && $targetUser->id !== $request->user()->id) {
            return response()->json(['message' => 'لا يمكن تغيير دور سوبر أدمن آخر.'], 403);
        }

        $validated = $request->validate([
            'role' => 'required|string|in:user,admin,super_admin',
        ]);

        $targetUser->role = $validated['role'];
        $targetUser->save();

        return response()->json([
            'message' => 'تم تغيير صلاحية المستخدم بنجاح.',
            'user' => $targetUser,
        ]);
    }

    /**
     * Promote a user to admin by phone number directly (Super Admin only).
     */
    public function promoteByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $clean = preg_replace('/[^0-9]/', '', $request->phone);
        $short = substr($clean, -10);
        $national = '0' . $short;

        $targetUser = User::where('phone', 'like', "%{$national}%")
            ->orWhere('phone', 'like', "%{$short}%")
            ->orWhere('phone', 'like', "%{$clean}%")
            ->first();

        if (!$targetUser) {
            return response()->json(['message' => 'لم يتم العثور على أي حساب مسجل بهذا الرقم.'], 404);
        }

        if ($targetUser->role === 'super_admin') {
            return response()->json(['message' => 'هذا الحساب هو بالفعل سوبر أدمن.'], 400);
        }

        $targetUser->role = 'admin';
        $targetUser->save();

        return response()->json([
            'message' => "تمت ترقية [{$targetUser->name}] برقم ({$targetUser->phone}) إلى مشرف بنجاح.",
            'user' => $targetUser,
        ]);
    }

    /**
     * Delete a user.
     */
    public function deleteUser(Request $request, $id)
    {
        $targetUser = User::findOrFail($id);

        if ($targetUser->role === 'super_admin') {
            return response()->json(['message' => 'لا يمكن حذف حساب السوبر أدمن.'], 403);
        }

        if ($targetUser->role === 'admin' && $request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'فقط السوبر أدمن يمكنه حذف المشرفين.'], 403);
        }

        $targetUser->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Toggle ban status for a user.
     */
    public function toggleBan(Request $request, $id)
    {
        $targetUser = User::findOrFail($id);

        if ($targetUser->role === 'super_admin') {
            return response()->json(['message' => 'لا يمكن حظر حساب السوبر أدمن.'], 403);
        }

        if ($targetUser->role === 'admin' && $request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'فقط السوبر أدمن يمكنه تعديل حالة حظر المشرفين.'], 403);
        }

        $targetUser->is_banned = !$targetUser->is_banned;
        $targetUser->save();

        $status = $targetUser->is_banned ? 'banned' : 'unbanned';

        return response()->json([
            'message' => "User has been $status successfully.",
            'user' => $targetUser,
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

        // --- Recent registered forms ---
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
                    'phone'      => $u->phone,
                    'email'      => $u->email,
                    'is_banned'  => $u->is_banned,
                    'created_at' => $u->created_at,
                    'nickname'   => $u->profile->nickname ?? null,
                    'profile'    => $u->profile,
                ];
            });

        // --- Subscription counts (Financials) — ONLY for Super Admin ---
        $isSuperAdmin = $request->user()->role === 'super_admin';
        $subscriptions = null;
        $period = $request->query('period', 'month');

        if ($isSuperAdmin) {
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

            $subscriptions = [
                'daily'   => (clone $subQuery)->where('type', 'daily')->count(),
                'weekly'  => (clone $subQuery)->where('type', 'weekly')->count(),
                'monthly' => (clone $subQuery)->where('type', 'monthly')->count(),
            ];
        }

        return response()->json([
            'total_users'      => $totalUsers,
            'males'            => $males,
            'females'          => $females,
            'banned_users'     => $banned,
            'pending_reports'  => $pendingReports,
            'recent_users'     => $recentUsers,
            'subscriptions'    => $subscriptions,
            'is_super_admin'   => $isSuperAdmin,
            'period'           => $period,
        ]);
    }

    /**
     * List all manual payments/receipts for admin review.
     */
    public function indexPayments(Request $request)
    {
        $status = $request->query('status');
        $plan = $request->query('plan');

        $query = Payment::with(['user.profile', 'subscription'])->latest();

        if ($status && in_array($status, ['pending', 'completed', 'failed'])) {
            $query->where('status', $status);
        }

        if ($plan && in_array($plan, ['daily', 'weekly', 'monthly'])) {
            $query->whereHas('subscription', function($q) use ($plan) {
                $q->where('type', $plan);
            });
        }

        return response()->json([
            'payments' => $query->get(),
        ]);
    }

    /**
     * Approve manual payment and activate user subscription.
     */
    public function approvePayment($id)
    {
        $payment = Payment::with('subscription')->findOrFail($id);

        if ($payment->status === 'completed') {
            return response()->json(['message' => 'هذا الطلب مفعل بالفعل.'], 400);
        }

        $subscription = $payment->subscription;

        if (!$subscription) {
            return response()->json(['message' => 'لم يتم العثور على اشتراك مرتبط بهذا الدفع.'], 404);
        }

        // Determine validity duration
        if ($subscription->type === 'daily') {
            $days = 1;
        } elseif ($subscription->type === 'weekly') {
            $days = 7;
        } else {
            $days = 30;
        }

        $payment->update([
            'status' => 'completed',
            'rejection_reason' => null,
        ]);

        // Check for any existing active subscription with unused views and stack them
        $existingSub = \App\Models\Subscription::where('user_id', $payment->user_id)
            ->where('id', '!=', $subscription->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        $remainingViews = 0;
        if ($existingSub) {
            $remainingViews = max(0, $existingSub->views_allowed - $existingSub->views_used);
            $existingSub->update(['status' => 'expired']);
        }

        $subscription->update([
            'status' => 'active',
            'views_allowed' => $subscription->views_allowed + $remainingViews,
            'expires_at' => now()->addDays($days),
        ]);

        return response()->json([
            'message' => 'تمت الموافقة على الدفع وتفعيل الاشتراك بنجاح.',
            'payment' => $payment,
            'subscription' => $subscription,
        ]);
    }

    /**
     * Reject manual payment with rejection reason.
     */
    public function rejectPayment(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ], [
            'rejection_reason.required' => 'يرجى كتابة سبب رفض الإيصال.',
        ]);

        $payment = Payment::with('subscription')->findOrFail($id);

        $payment->update([
            'status' => 'failed',
            'rejection_reason' => $request->rejection_reason,
        ]);

        if ($payment->subscription) {
            $payment->subscription->update([
                'status' => 'expired',
            ]);
        }

        return response()->json([
            'message' => 'تم رفض الإيصال وتحديث الحالة.',
            'payment' => $payment,
        ]);
    }
}

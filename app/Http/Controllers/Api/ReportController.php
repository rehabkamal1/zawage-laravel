<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Auto-ban threshold: number of distinct female reporters needed to trigger auto-ban.
     */
    const AUTO_BAN_THRESHOLD = 3;

    /**
     * Submit a report.
     * After saving, checks if the reported user has reached the auto-ban threshold
     * (3+ distinct female users reporting them) and bans them automatically.
     */
    public function store(Request $request)
    {
        $request->validate([
            'reported_id' => 'required|exists:users,id',
            'reason'      => 'required|string|max:1000',
        ]);

        $reporter = $request->user();

        // Prevent reporting oneself
        if ($reporter->id == $request->reported_id) {
            return response()->json(['message' => 'لا يمكنك الإبلاغ عن نفسك.'], 422);
        }

        // Prevent duplicate reports from the same user on the same person
        $alreadyReported = Report::where('reporter_id', $reporter->id)
            ->where('reported_id', $request->reported_id)
            ->exists();

        if ($alreadyReported) {
            return response()->json(['message' => 'لقد أبلغت عن هذا الشخص من قبل.'], 422);
        }

        $report = Report::create([
            'reporter_id' => $reporter->id,
            'reported_id' => $request->reported_id,
            'reason'      => $request->reason,
        ]);

        // ─── Auto-ban check ───────────────────────────────────────────────
        // Count how many DISTINCT female users have reported this person.
        $femaleReporterCount = Report::where('reported_id', $request->reported_id)
            ->join('users', 'users.id', '=', 'reports.reporter_id')
            ->where('users.gender', 'female')
            ->distinct('reports.reporter_id')
            ->count('reports.reporter_id');

        $autoBanned = false;

        if ($femaleReporterCount >= self::AUTO_BAN_THRESHOLD) {
            $reportedUser = User::find($request->reported_id);

            if ($reportedUser && !$reportedUser->is_banned) {
                $reportedUser->is_banned = true;
                $reportedUser->save();
                $autoBanned = true;
            }
        }
        // ─────────────────────────────────────────────────────────────────

        return response()->json([
            'message'      => 'تم إرسال البلاغ بنجاح.',
            'report'       => $report,
            'auto_banned'  => $autoBanned,
            'report_count' => $femaleReporterCount,
        ], 201);
    }

    /**
     * List all reports (Admin only).
     */
    public function index()
    {
        $reports = Report::with(['reporter:id,name,gender,email', 'reported:id,name,gender,email'])
            ->latest()
            ->get()
            ->map(function ($r) {
                // Count distinct female reporters for the reported user
                $femaleCount = Report::where('reported_id', $r->reported_id)
                    ->join('users', 'users.id', '=', 'reports.reporter_id')
                    ->where('users.gender', 'female')
                    ->distinct('reports.reporter_id')
                    ->count('reports.reporter_id');

                return array_merge($r->toArray(), [
                    'female_report_count' => $femaleCount,
                    'threshold'           => self::AUTO_BAN_THRESHOLD,
                ]);
            });

        return response()->json(['reports' => $reports]);
    }

    /**
     * Update report status (Admin only).
     */
    public function update(Request $request, $id)
    {
        $report = Report::findOrFail($id);

        $request->validate([
            'status' => 'required|string|in:pending,resolved,dismissed',
        ]);

        $report->update(['status' => $request->status]);

        return response()->json([
            'message' => 'تم تحديث حالة البلاغ بنجاح.',
            'report'  => $report,
        ]);
    }
}

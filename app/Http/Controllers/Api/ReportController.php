<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Submit a report.
     */
    public function store(Request $request)
    {
        $request->validate([
            'reported_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:1000',
        ]);

        $report = Report::create([
            'reporter_id' => $request->user()->id,
            'reported_id' => $request->reported_id,
            'reason' => $request->reason,
        ]);

        return response()->json([
            'message' => 'Report submitted successfully.',
            'report' => $report,
        ], 201);
    }

    /**
     * List all reports (Admin only).
     */
    public function index()
    {
        return response()->json([
            'reports' => Report::with(['reporter', 'reported'])->latest()->get(),
        ]);
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
            'message' => 'Report status updated successfully.',
            'report' => $report,
        ]);
    }
}

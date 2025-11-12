<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AidRequest;
use App\Models\BeneficiaryDocumentSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Get comprehensive dashboard analytics
     */
    public function dashboard(Request $request)
    {
        $user = Auth::user();
        $days = (int) $request->get('days', 30);
        $startDate = Carbon::now()->subDays($days);
        
        // Only admins and directors can access full analytics
        if (!$user->isAdmin() && !$user->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to access analytics.'
            ], 403);
        }

        $analytics = [
            'totals' => $this->getTotals($startDate),
            'enrollment_stats' => $this->getEnrollmentStats($startDate),
            'aid_request_stats' => $this->getAidRequestStats($startDate),
            'conversion_rates' => $this->getConversionRates($startDate),
            'trends' => $this->getTrends($startDate),
            'top_caseworkers' => $this->getTopCaseworkers($startDate),
            'recent_activity' => $this->getRecentActivity(20)
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    private function getTotals($startDate)
    {
        return [
            'beneficiaries' => User::where('systemrole_id', function($query) {
                $query->select('role_id')
                      ->from('system_roles')
                      ->where('name', 'beneficiary')
                      ->limit(1);
            })->count(),
            'aid_disbursed' => AidRequest::where('status', 'approved')
                                        ->where('created_at', '>=', $startDate)
                                        ->sum('amount'),
            'total_submissions' => BeneficiaryDocumentSubmission::where('created_at', '>=', $startDate)->count(),
            'total_requests' => AidRequest::where('created_at', '>=', $startDate)->count()
        ];
    }

    private function getEnrollmentStats($startDate)
    {
        return BeneficiaryDocumentSubmission::select('status', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    private function getAidRequestStats($startDate)
    {
        return AidRequest::select('status', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    private function getConversionRates($startDate)
    {
        $enrollmentTotal = BeneficiaryDocumentSubmission::where('created_at', '>=', $startDate)->count();
        $enrollmentApproved = BeneficiaryDocumentSubmission::where('status', 'approved')
                                                         ->where('created_at', '>=', $startDate)
                                                         ->count();
        
        $aidTotal = AidRequest::where('created_at', '>=', $startDate)->count();
        $aidApproved = AidRequest::where('status', 'approved')
                                ->where('created_at', '>=', $startDate)
                                ->count();
        
        // Count beneficiaries who have both approved enrollment and submitted aid request
        $enrollmentToAid = AidRequest::whereHas('beneficiary.documentSubmissions', function($query) use ($startDate) {
            $query->where('status', 'approved')
                  ->where('created_at', '>=', $startDate);
        })->distinct('beneficiary_id')->count();

        return [
            'enrollment_approval_rate' => $enrollmentTotal > 0 ? ($enrollmentApproved / $enrollmentTotal) * 100 : 0,
            'aid_approval_rate' => $aidTotal > 0 ? ($aidApproved / $aidTotal) * 100 : 0,
            'enrollment_to_aid_conversion' => $enrollmentApproved > 0 ? ($enrollmentToAid / $enrollmentApproved) * 100 : 0
        ];
    }

    private function getTrends($startDate)
    {
        $enrollmentTrends = BeneficiaryDocumentSubmission::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $aidTrends = AidRequest::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'enrollment_submissions' => $enrollmentTrends,
            'aid_requests' => $aidTrends
        ];
    }

    private function getTopCaseworkers($startDate)
    {
        return User::select(
                'users.*',
                DB::raw('COUNT(DISTINCT assigned.id) as beneficiaries_count'),
                DB::raw('(SELECT COUNT(*) FROM beneficiary_document_submissions bds WHERE bds.reviewed_by = users.id AND bds.created_at >= ?) + (SELECT COUNT(*) FROM aid_requests ar WHERE ar.reviewed_by = users.id AND ar.created_at >= ?) as reviews_completed')
            )
            ->leftJoin('users as assigned', 'assigned.caseworker_id', '=', 'users.id')
            ->whereHas('systemRole', function($query) {
                $query->where('name', 'caseworker');
            })
            ->groupBy('users.id')
            ->orderByDesc('reviews_completed')
            ->setBindings([$startDate, $startDate], 'select')
            ->limit(10)
            ->get()
            ->map(function($caseworker) {
                return [
                    'id' => $caseworker->id,
                    'name' => trim($caseworker->firstname . ' ' . $caseworker->lastname),
                    'beneficiaries_count' => $caseworker->beneficiaries_count,
                    'reviews_completed' => $caseworker->reviews_completed
                ];
            });
    }

    private function getRecentActivity($limit = 20)
    {
        $activities = collect();

        // Recent enrollment submissions
        $enrollmentActivities = BeneficiaryDocumentSubmission::with(['beneficiary', 'reviewer'])
            ->whereNotNull('reviewed_at')
            ->orderByDesc('reviewed_at')
            ->limit($limit / 2)
            ->get()
            ->map(function($submission) {
                return [
                    'id' => 'enrollment_' . $submission->id,
                    'type' => $submission->status,
                    'description' => "Enrollment verification for {$submission->beneficiary->firstname} {$submission->beneficiary->lastname} was {$submission->status}" . 
                                   ($submission->reviewer ? " by {$submission->reviewer->firstname} {$submission->reviewer->lastname}" : ''),
                    'created_at' => $submission->reviewed_at
                ];
            });

        // Recent aid request reviews
        $aidActivities = AidRequest::with(['beneficiary', 'reviewer'])
            ->whereNotNull('reviewed_at')
            ->orderByDesc('reviewed_at')
            ->limit($limit / 2)
            ->get()
            ->map(function($request) {
                return [
                    'id' => 'aid_' . $request->id,
                    'type' => $request->status,
                    'description' => "Aid request for ₱" . number_format($request->amount, 2) . " from {$request->beneficiary->firstname} {$request->beneficiary->lastname} was {$request->status}" .
                                   ($request->reviewer ? " by {$request->reviewer->firstname} {$request->reviewer->lastname}" : ''),
                    'created_at' => $request->reviewed_at
                ];
            });

        return $activities->merge($enrollmentActivities)
                         ->merge($aidActivities)
                         ->sortByDesc('created_at')
                         ->take($limit)
                         ->values();
    }
}

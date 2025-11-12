<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedbackController extends Controller
{
    /**
     * Get all feedback (Admin only)
     */
    public function index(Request $request)
    {
        $query = Feedback::with(['user', 'facility', 'reviewer'])
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by rating
        if ($request->has('rating') && $request->rating !== 'all') {
            $query->where('rating', $request->rating);
        }

        // Filter by category
        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Search by user name, email, or comment
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('comment', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('firstname', 'like', "%{$search}%")
                            ->orWhere('lastname', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $feedback = $query->get();

        return response()->json([
            'success' => true,
            'data' => $feedback,
            'statistics' => [
                'total' => Feedback::count(),
                'pending' => Feedback::where('status', 'pending')->count(),
                'reviewed' => Feedback::where('status', 'reviewed')->count(),
                'average_rating' => round(Feedback::avg('rating'), 2),
            ]
        ]);
    }

    /**
     * Get user's own feedback
     */
    public function myFeedback(Request $request)
    {
        $feedback = Feedback::where('user_id', auth()->id())
            ->with(['facility', 'reviewer'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $feedback
        ]);
    }

    /**
     * Submit new feedback
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:10|max:1000',
            'category' => 'nullable|string|in:service,feature,support,general',
            'facility_id' => 'nullable|exists:financial_aid,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $feedback = Feedback::create([
            'user_id' => auth()->id(),
            'facility_id' => $request->facility_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'category' => $request->category ?? 'general',
            'status' => 'pending',
        ]);

        $feedback->load(['user', 'facility']);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your feedback!',
            'data' => $feedback
        ], 201);
    }

    /**
     * Update feedback (Admin: add response and mark as reviewed)
     */
    public function update(Request $request, $id)
    {
        $feedback = Feedback::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'admin_response' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:pending,reviewed,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = [];

        if ($request->has('admin_response')) {
            $updateData['admin_response'] = $request->admin_response;
        }

        if ($request->has('status')) {
            $updateData['status'] = $request->status;
            
            if ($request->status === 'reviewed' && !$feedback->reviewed_at) {
                $updateData['reviewed_by'] = auth()->id();
                $updateData['reviewed_at'] = now();
            }
        }

        $feedback->update($updateData);
        $feedback->load(['user', 'facility', 'reviewer']);

        return response()->json([
            'success' => true,
            'message' => 'Feedback updated successfully',
            'data' => $feedback
        ]);
    }

    /**
     * Delete feedback (Admin only)
     */
    public function destroy($id)
    {
        $feedback = Feedback::findOrFail($id);
        $feedback->delete();

        return response()->json([
            'success' => true,
            'message' => 'Feedback deleted successfully'
        ]);
    }

    /**
     * Get feedback statistics (Admin)
     */
    public function statistics()
    {
        $totalFeedback = Feedback::count();
        $averageRating = round(Feedback::avg('rating'), 2);
        
        $ratingDistribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $ratingDistribution[$i] = Feedback::where('rating', $i)->count();
        }

        $categoryDistribution = Feedback::selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        $statusDistribution = Feedback::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $totalFeedback,
                'average_rating' => $averageRating,
                'rating_distribution' => $ratingDistribution,
                'category_distribution' => $categoryDistribution,
                'status_distribution' => $statusDistribution,
            ]
        ]);
    }
}

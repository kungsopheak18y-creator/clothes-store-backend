<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Order;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    // Get all reviews for a product
    public function index($productId)
    {
        $reviews = Review::where('product_id', $productId)
            ->with('user:id,first_name,last_name')
            ->latest()
            ->get();

        return response()->json([
            'reviews' => $reviews,
            'average' => round($reviews->avg('rating'), 1),
            'total'   => $reviews->count(),
        ]);
    }

    // Submit a review
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'order_id'   => 'required|exists:orders,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string|max:1000',
        ]);

        // Check if user actually bought this product in this order
        $order = Order::where('id', $request->order_id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'delivered') // only delivered orders
            ->whereHas('items', function ($q) use ($request) {
                $q->where('product_id', $request->product_id);
            })
            ->first();

        if (!$order) {
            return response()->json(['message' => 'You can only review products you have received.'], 403);
        }

        // Check if already reviewed
        $existing = Review::where('user_id', $request->user()->id)
            ->where('product_id', $request->product_id)
            ->where('order_id', $request->order_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You already reviewed this product.'], 409);
        }

        $review = Review::create([
            'user_id'    => $request->user()->id,
            'product_id' => $request->product_id,
            'order_id'   => $request->order_id,
            'rating'     => $request->rating,
            'comment'    => $request->comment,
        ]);

        return response()->json(['message' => 'Review submitted!', 'review' => $review->load('user:id,first_name,last_name')], 201);
    }
}
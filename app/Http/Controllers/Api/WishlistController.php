<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    // GET /api/wishlist — get all wishlist items for logged in user
    public function index(Request $request)
    {
        $wishlist = Wishlist::where('user_id', $request->user()->id)
            ->with(['product' => function($query) {
                $query->with(['category', 'brand']);
            }])
            ->get();

        return response()->json([
            'message'  => 'Wishlist retrieved successfully',
            'wishlist' => $wishlist,
        ]);
    }

    // POST /api/wishlist/toggle — add or remove product
    public function toggle(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $existing = Wishlist::where('user_id', $request->user()->id)
            ->where('product_id', $request->product_id)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'message'     => 'Removed from wishlist',
                'wishlisted'  => false,
            ]);
        }

        Wishlist::create([
            'user_id'    => $request->user()->id,
            'product_id' => $request->product_id,
        ]);

        return response()->json([
            'message'    => 'Added to wishlist',
            'wishlisted' => true,
        ]);
    }

    // GET /api/wishlist/ids — get all wishlisted product IDs (for heart icon state)
    public function ids(Request $request)
    {
        $ids = Wishlist::where('user_id', $request->user()->id)
            ->pluck('product_id');

        return response()->json([
            'ids' => $ids,
        ]);
    }
}
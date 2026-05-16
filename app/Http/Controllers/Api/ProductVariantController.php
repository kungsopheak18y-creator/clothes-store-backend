<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    // GET /products/{product}/variants - public
    public function index(Product $product)
    {
        return response()->json([
            'message'  => 'Variants retrieved successfully',
            'variants' => $product->variants,
        ]);
    }

    // POST /products/{product}/variants - admin only
    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'size'  => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'stock' => 'required|integer|min:0',
            'sku'   => 'nullable|string|unique:product_variants,sku',
            'price' => 'nullable|numeric|min:0',
        ]);

        $validated['product_id'] = $product->id;

        $variant = ProductVariant::create($validated);

        return response()->json([
            'message' => 'Variant created successfully',
            'variant' => $variant,
        ], 201);
    }

    // PUT /products/{product}/variants/{variant} - admin only
    public function update(Request $request, Product $product, ProductVariant $variant)
    {
        $validated = $request->validate([
            'size'  => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'stock' => 'sometimes|integer|min:0',
            'sku'   => 'nullable|string|unique:product_variants,sku,' . $variant->id,
            'price' => 'nullable|numeric|min:0',
        ]);

        $variant->update($validated);

        return response()->json([
            'message' => 'Variant updated successfully',
            'variant' => $variant,
        ]);
    }

    // DELETE /products/{product}/variants/{variant} - admin only
    public function destroy(Product $product, ProductVariant $variant)
    {
        $variant->delete();

        return response()->json([
            'message' => 'Variant deleted successfully',
        ]);
    }

    // GET /admin/low-stock - admin only
    public function lowStock(Request $request)
    {
        $threshold = $request->get('threshold', 5);

        $variants = ProductVariant::with('product')
            ->where('stock', '<=', $threshold)
            ->orderBy('stock', 'asc')
            ->get();

        return response()->json([
            'message'   => 'Low stock variants retrieved successfully',
            'threshold' => $threshold,
            'count'     => $variants->count(),
            'variants'  => $variants,
        ]);
    }
}
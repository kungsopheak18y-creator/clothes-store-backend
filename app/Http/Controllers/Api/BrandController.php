<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

class BrandController extends Controller
{
    private function uploadToCloudinary($file): string
    {
        $cloudinary = new Cloudinary(
            Configuration::instance([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key'    => env('CLOUDINARY_API_KEY'),
                    'api_secret' => env('CLOUDINARY_API_SECRET'),
                ],
                'url' => ['secure' => true],
            ])
        );

        $result = $cloudinary->uploadApi()->upload(
            $file->getRealPath(),
            ['folder' => 'clothes-backend/brands']
        );

        return $result['secure_url'];
    }

    public function index()
    {
        $brands = Brand::withCount('products')->orderBy('name')->get();

        return response()->json([
            'message' => 'Brands retrieved successfully',
            'brands'  => $brands,
        ]);
    }

    public function show(Brand $brand)
    {
        return response()->json([
            'message' => 'Brand retrieved successfully',
            'brand'   => $brand->loadCount('products')->load('products'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:brands,name',
            'description' => 'nullable|string',
            'logo'        => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $this->uploadToCloudinary($request->file('logo'));
        }

        $brand = Brand::create($validated);

        return response()->json([
            'message' => 'Brand created successfully',
            'brand'   => $brand,
        ], 201);
    }

    public function update(Request $request, Brand $brand)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255|unique:brands,name,' . $brand->id,
            'description' => 'nullable|string',
            'logo'        => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $this->uploadToCloudinary($request->file('logo'));
        }

        $brand->update($validated);

        return response()->json([
            'message' => 'Brand updated successfully',
            'brand'   => $brand,
        ]);
    }

    public function destroy(Brand $brand)
    {
        if ($brand->products()->count() > 0) {
            return response()->json([
                'error' => 'Cannot delete brand with existing products. Please delete or reassign products first.',
            ], 400);
        }

        $brand->delete();

        return response()->json([
            'success' => true,
            'message' => 'Brand deleted successfully',
        ]);
    }
}
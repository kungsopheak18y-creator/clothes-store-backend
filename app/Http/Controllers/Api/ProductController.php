<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

class ProductController extends Controller
{
    private function getCloudinary(): Cloudinary
    {
        return new Cloudinary(
            Configuration::instance([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key'    => env('CLOUDINARY_API_KEY'),
                    'api_secret' => env('CLOUDINARY_API_SECRET'),
                ],
                'url' => ['secure' => true],
            ])
        );
    }

    private function uploadImage($file): string
    {
        $result = $this->getCloudinary()->uploadApi()->upload(
            $file->getRealPath(),
            ['folder' => 'clothes-backend/products']
        );
        return $result['secure_url'];
    }

    private function deleteImage(string $url): void
    {
        try {
            $uploadIndex = strpos($url, '/upload/');
            if ($uploadIndex === false) return;

            $afterUpload = substr($url, $uploadIndex + 8);
            $parts       = explode('/', $afterUpload);

            if (isset($parts[0]) && str_starts_with($parts[0], 'v')) {
                array_shift($parts);
            }

            $fullPath = implode('/', $parts);
            $publicId = substr($fullPath, 0, strrpos($fullPath, '.'));

            $this->getCloudinary()->uploadApi()->destroy($publicId);
        } catch (\Exception $e) {
            \Log::warning("Failed to delete Cloudinary image: $url — " . $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand', 'variants']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }
        if ($request->filled('size')) {
            $size = $request->size;
            $query->whereHas('variants', fn($q) => $q->where('size', $size));
        }

        $sortBy    = in_array($request->get('sort_by'), ['name', 'price', 'created_at'])
            ? $request->get('sort_by') : 'created_at';
        $sortOrder = $request->get('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $products = $query->paginate($request->get('per_page', 10));

        return response()->json([
            'message'  => 'Products retrieved successfully',
            'products' => $products,
        ]);
    }

    public function show(Product $product)
    {
        return response()->json([
            'message' => 'Product retrieved successfully',
            'product' => $product->load(['category', 'brand', 'variants']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'brand_id'    => 'required|exists:brands,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'images'      => 'nullable|array',
            'images.*'    => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'variants'    => 'required|string',
        ]);

        $variants = json_decode($request->variants, true);
        if (!is_array($variants) || count($variants) === 0) {
            return response()->json(['error' => 'variants must be a non-empty JSON array'], 422);
        }

        $imageUrls = [];
        foreach ($request->file('images', []) as $file) {
            $imageUrls[] = $this->uploadImage($file);
        }

        $variants = array_map(function ($v) use ($request) {
            if (empty($v['sku'])) {
                $brandCode = strtoupper(substr($request->name, 0, 3));
                $colorCode = strtoupper(substr($v['color'] ?? 'NA', 0, 3));
                $v['sku']  = str_replace(' ', '', "$brandCode-$colorCode-{$v['size']}");
            }
            return $v;
        }, $variants);

        $product = Product::create([
            'category_id' => $request->category_id,
            'brand_id'    => $request->brand_id,
            'name'        => $request->name,
            'description' => $request->description,
            'price'       => $request->price,
            'images'      => $imageUrls,
        ]);

        foreach ($variants as $v) {
            $product->variants()->create([
                'size'  => $v['size'],
                'color' => $v['color'],
                'stock' => $v['stock'] ?? 0,
                'sku'   => $v['sku'] ?? null,
            ]);
        }

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product->load(['category', 'brand', 'variants']),
        ], 201);
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'category_id'     => 'sometimes|exists:categories,id',
            'brand_id'        => 'sometimes|exists:brands,id',
            'name'            => 'sometimes|string|max:255',
            'description'     => 'nullable|string',
            'price'           => 'sometimes|numeric|min:0',
            'images'          => 'nullable|array',
            'images.*'        => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'existing_images' => 'nullable|string',
            'removed_images'  => 'nullable|string',
            'variants'        => 'nullable|string',
        ]);

        if ($request->filled('removed_images')) {
            foreach (json_decode($request->removed_images, true) ?? [] as $url) {
                $this->deleteImage($url);
            }
        }

        $existingImages = $request->filled('existing_images')
            ? json_decode($request->existing_images, true) : [];

        $newImages = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images', []) as $file) {
                $newImages[] = $this->uploadImage($file);
            }
        }

        $finalImages = array_merge($existingImages ?? [], $newImages);

        $product->update([
            'category_id' => $request->category_id ?? $product->category_id,
            'brand_id'    => $request->brand_id    ?? $product->brand_id,
            'name'        => $request->name        ?? $product->name,
            'description' => $request->has('description') ? $request->description : $product->description,
            'price'       => $request->price       ?? $product->price,
            'images'      => count($finalImages) > 0 ? $finalImages : $product->images,
        ]);

        if ($request->filled('variants')) {
            $incomingVariants = json_decode($request->variants, true) ?? [];
            $existingIds      = $product->variants->pluck('id')->toArray();
            $incomingIds      = array_filter(array_column($incomingVariants, 'id'));

            $toDelete = array_diff($existingIds, $incomingIds);
            if (!empty($toDelete)) {
                $referenced   = OrderItem::whereIn('product_variant_id', $toDelete)->pluck('product_variant_id')->toArray();
                $safeToDelete = array_diff($toDelete, $referenced);
                if (!empty($safeToDelete)) {
                    ProductVariant::whereIn('id', $safeToDelete)->delete();
                }
            }

            foreach ($incomingVariants as $v) {
                if (!empty($v['id']) && in_array($v['id'], $existingIds)) {
                    ProductVariant::where('id', $v['id'])->update([
                        'size'  => $v['size'],
                        'color' => $v['color'],
                        'stock' => $v['stock'] ?? 0,
                        'sku'   => $v['sku'] ?? null,
                    ]);
                } else {
                    $product->variants()->create([
                        'size'  => $v['size'],
                        'color' => $v['color'],
                        'stock' => $v['stock'] ?? 0,
                        'sku'   => $v['sku'] ?? null,
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product->fresh()->load(['category', 'brand', 'variants']),
        ]);
    }

    public function destroy(Product $product)
    {
        foreach ($product->images ?? [] as $url) {
            $this->deleteImage($url);
        }

        $product->variants()->delete();
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }
}
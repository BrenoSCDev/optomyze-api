<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageController extends Controller
{
    /**
     * List images of a product
     */
    public function index(Product $product): JsonResponse
    {
        $images = $product->images()
            ->ordered()
            ->get();

        return response()->json($images);
    }

    /**
     * Store / upload a new product image
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'image'         => 'required|image|max:5120', // 5MB
            'image_type'    => 'nullable|string|max:50',
            'alt_text'      => 'nullable|string|max:255',
            // 'is_primary'    => 'sometimes|boolean',
            'display_order' => 'nullable|integer',
        ]);

        $file = $validated['image'];

        $path = $file->store(
            'products/' . $product->id,
            'public'
        );

        // If marked as primary, unset previous primary image
        if (!empty($validated['is_primary'])) {
            $product->images()->update(['is_primary' => false]);
        }

        [$width, $height] = getimagesize($file);

        $image = ProductImage::create([
            'product_id'    => $product->id,
            'storage_path'  => $path,
            'filename'      => $file->getClientOriginalName(),
            'display_order' => $validated['display_order'] 
                ?? ($product->images()->max('display_order') + 1),
            'is_primary'    => $validated['is_primary'] ?? false,
            'alt_text'      => $validated['alt_text'] ?? null,
            'file_size'     => $file->getSize(),
            'mime_type'     => $file->getMimeType(),
            'width'         => $width,
            'height'        => $height,
        ]);

        return response()->json($image, 201);
    }

    /**
     * Update image metadata (no file replacement)
     */
    public function update(Request $request, ProductImage $productImage): JsonResponse
    {
        $validated = $request->validate([
            'image_type'    => 'nullable|string|max:50',
            'alt_text'      => 'nullable|string|max:255',
            'display_order' => 'nullable|integer',
            'is_primary'    => 'sometimes|boolean',
        ]);

        if (isset($validated['is_primary']) && $validated['is_primary']) {
            ProductImage::where('product_id', $productImage->product_id)
                ->where('id', '!=', $productImage->id)
                ->update(['is_primary' => false]);
        }

        $productImage->update($validated);

        return response()->json($productImage);
    }

    /**
     * Set image as primary
     */
    public function setPrimary(ProductImage $productImage): JsonResponse
    {
        ProductImage::where('product_id', $productImage->product_id)
            ->update(['is_primary' => false]);

        $productImage->update(['is_primary' => true]);

        return response()->json([
            'message' => 'Primary image updated',
            'image'   => $productImage,
        ]);
    }

    /**
     * Reorder images
     * Expected payload:
     * [
     *   { "id": 1, "display_order": 1 },
     *   { "id": 2, "display_order": 2 }
     * ]
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'images'               => 'required|array',
            'images.*.id'          => 'required|exists:product_images,id',
            'images.*.display_order' => 'required|integer',
        ]);

        foreach ($validated['images'] as $image) {
            ProductImage::where('id', $image['id'])
                ->update(['display_order' => $image['display_order']]);
        }

        return response()->json(['message' => 'Images reordered successfully']);
    }

    /**
     * Delete image (file + record)
     */
    public function destroy(ProductImage $productImage): JsonResponse
    {
        $productImage->delete();

        return response()->json(['message' => 'Image deleted successfully']);
    }
}

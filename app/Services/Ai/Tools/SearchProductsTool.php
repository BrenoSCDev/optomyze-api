<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;

class SearchProductsTool implements ToolInterface
{
    public const KEY = 'search_products';

    private const MAX_RESULTS = 10;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Search Product Catalog';
    }

    public function description(): string
    {
        return 'Search the company\'s product catalog by name, description, SKU, category or specification. Returns full product details including price, categories and every custom specification field. Always use this before quoting any price or spec — never invent product information.';
    }

    public function parametersSchema(ToolContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term matched against product name, description, SKU and specification values. Leave empty to list the catalog.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return true;
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        $products = Product::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->with(['categories:id,name', 'fields' => fn ($q) => $q->orderBy('display_order')])
            ->when($query !== '', function ($builder) use ($query) {
                $builder->where(function ($q) use ($query) {
                    $like = "%{$query}%";
                    $q->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhereHas('categories', fn ($c) => $c->where('name', 'like', $like))
                        ->orWhereHas('fields', fn ($f) => $f->where('field_value', 'like', $like));
                });
            })
            ->orderByDesc('is_favorite')
            ->limit(self::MAX_RESULTS)
            ->get();

        if ($products->isEmpty()) {
            return ToolResult::ok(['products' => [], 'message' => 'No products matched.']);
        }

        return ToolResult::ok([
            'products' => $products->map(fn (Product $product) => $this->formatProduct($product))->all(),
        ]);
    }

    /**
     * Everything the CRM knows about the product: base fields, categories
     * and all custom specification fields (e.g. engine, mileage, color for
     * a car dealership) so the LLM can answer detailed questions.
     */
    private function formatProduct(Product $product): array
    {
        $specifications = [];

        foreach ($product->fields as $field) {
            $value = trim((string) $field->field_value);

            if ($field->field_key && $value !== '') {
                $specifications[$field->field_key] = $value;
            }
        }

        return array_filter([
            'name' => $product->name,
            'description' => $product->description,
            'sku' => $product->sku,
            'price' => $product->base_price,
            'type' => $product->type,
            'categories' => $product->categories->pluck('name')->all(),
            'specifications' => $specifications,
            'metadata' => $product->metadata,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }
}

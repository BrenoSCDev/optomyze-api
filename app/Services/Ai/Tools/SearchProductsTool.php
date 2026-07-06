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
        return 'Search the company\'s product catalog by name or description. Returns product names, descriptions and prices. Always use this before quoting any price — never invent product information.';
    }

    public function parametersSchema(ToolContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term matched against product name and description. Leave empty to list the catalog.',
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
            ->when($query !== '', function ($builder) use ($query) {
                $builder->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->orWhere('sku', 'like', "%{$query}%");
                });
            })
            ->orderByDesc('is_favorite')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'description', 'sku', 'base_price', 'currency', 'type']);

        if ($products->isEmpty()) {
            return ToolResult::ok(['products' => [], 'message' => 'No products matched.']);
        }

        return ToolResult::ok([
            'products' => $products->map(fn (Product $product) => [
                'name' => $product->name,
                'description' => $product->description,
                'sku' => $product->sku,
                'price' => $product->base_price,
                'currency' => $product->currency,
                'type' => $product->type,
            ])->all(),
        ]);
    }
}

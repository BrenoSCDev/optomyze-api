<?php

namespace App\Http\Controllers;

use App\Models\Widget;
use App\Services\Widgets\WidgetTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Unauthenticated endpoints hit by the embed script running on the customer's
 * website. Nothing here exposes CRM internals: widgets are addressed by their
 * public id and only the render-safe slice of the config is returned.
 */
class PublicWidgetController extends Controller
{
    /**
     * Render configuration for one widget.
     *
     * A browser always sends Origin/Referer, so when one is present it must
     * match the configured domains. Requests without either (curl, previews)
     * may read the public config but cannot submit.
     */
    public function config(Request $request, string $publicId)
    {
        $widget = $this->resolveWidget($publicId);

        if (!$widget) {
            return response()->json(['message' => 'Widget not found.'], 404);
        }

        $origin = $this->requestOrigin($request);

        if ($origin !== null && !$widget->allowsOrigin($origin)) {
            return response()->json(['message' => 'This widget is not enabled for this domain.'], 403);
        }

        $type = WidgetTypeRegistry::resolve($widget->type);

        return response()->json(['data' => $type->publicConfig($widget)]);
    }

    /**
     * Accept a visitor submission (form fields, chat message, …).
     */
    public function submit(Request $request, string $publicId)
    {
        $widget = $this->resolveWidget($publicId);

        if (!$widget) {
            return response()->json(['message' => 'Widget not found.'], 404);
        }

        $origin = $this->requestOrigin($request);

        if ($origin === null || !$widget->allowsOrigin($origin)) {
            Log::warning('Widget submission rejected: origin not allowed', [
                'widget_id' => $widget->id,
                'origin' => $origin,
            ]);

            return response()->json(['message' => 'This widget is not enabled for this domain.'], 403);
        }

        $type = WidgetTypeRegistry::resolve($widget->type);

        try {
            $result = $type->handleSubmission($widget, $request);
        } catch (\Throwable $e) {
            Log::error('Widget submission failed', [
                'widget_id' => $widget->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'We could not process your submission. Please try again.'], 500);
        }

        if (($result['status'] ?? null) === 'invalid') {
            return response()->json([
                'message' => 'Please review the highlighted fields.',
                'errors' => $result['errors'] ?? [],
            ], 422);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Thank you!',
        ], 201);
    }

    private function resolveWidget(string $publicId): ?Widget
    {
        return Widget::where('public_id', $publicId)->active()->first();
    }

    private function requestOrigin(Request $request): ?string
    {
        return $request->header('Origin') ?: $request->header('Referer') ?: null;
    }
}

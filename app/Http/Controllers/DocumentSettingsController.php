<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Models\DocumentVariable;
use App\Services\Documents\DocumentAuthorizer;
use App\Services\Documents\VariableRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The Documents module's configuration: types, custom variables, and the
 * catalogue of variables an author can use.
 *
 * Three small resources in one controller because they are one screen —
 * splitting them would be three files of identical scaffolding.
 */
class DocumentSettingsController extends Controller
{
    public function __construct(
        private readonly DocumentAuthorizer $authorizer,
        private readonly VariableRegistry $registry,
    ) {
    }

    /* ============================================================
     | Types
     ============================================================ */

    public function types(Request $request)
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        // Seeded on first read rather than at company creation, so companies
        // that predate this module get their types without a backfill.
        DocumentType::ensureSystemTypes($user->company_id);

        $query = DocumentType::fromCompany($user->company_id);

        if (!$request->boolean('include_inactive')) {
            $query->active();
        }

        return response()->json([
            'data' => $query->orderBy('position')->orderBy('label')->get()
                ->map(fn (DocumentType $t) => $t->toPublicArray())
                ->values(),
            'categories' => DocumentType::CATEGORIES,
        ]);
    }

    public function storeType(Request $request)
    {
        $user = Auth::user();

        if (!$this->authorizer->canManageTypes($user)) {
            return response()->json(['message' => 'Apenas gestores podem criar tipos.'], 403);
        }

        $validated = $request->validate([
            'label'              => ['required', 'string', 'max:255'],
            'category'           => ['required', 'string', 'in:' . implode(',', DocumentType::CATEGORIES)],
            'description'        => ['nullable', 'string', 'max:1000'],
            'requires_signature' => ['nullable', 'boolean'],
            'requires_approval'  => ['nullable', 'boolean'],
            'default_template_id' => ['nullable', 'integer'],
        ]);

        $type = DocumentType::create(array_merge($validated, [
            'company_id' => $user->company_id,
            'key'        => $this->uniqueKey($user->company_id, $validated['label']),
            'is_system'  => false,
        ]));

        return response()->json([
            'message' => 'Tipo criado.',
            'data'    => $type->toPublicArray(),
        ], 201);
    }

    public function updateType(Request $request, int $id)
    {
        $user = Auth::user();

        if (!$this->authorizer->canManageTypes($user)) {
            return response()->json(['message' => 'Apenas gestores podem editar tipos.'], 403);
        }

        $type = DocumentType::fromCompany($user->company_id)->find($id);

        if (!$type) {
            return response()->json(['message' => 'Tipo não encontrado.'], 404);
        }

        $type->update($request->validate([
            'label'               => ['sometimes', 'string', 'max:255'],
            'description'         => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category'            => ['sometimes', 'string', 'in:' . implode(',', DocumentType::CATEGORIES)],
            'requires_signature'  => ['sometimes', 'boolean'],
            'requires_approval'   => ['sometimes', 'boolean'],
            'default_template_id' => ['sometimes', 'nullable', 'integer'],
            'is_active'           => ['sometimes', 'boolean'],
            'position'            => ['sometimes', 'integer', 'min:0'],
        ]));

        return response()->json([
            'message' => 'Tipo atualizado.',
            'data'    => $type->fresh()->toPublicArray(),
        ]);
    }

    public function destroyType(Request $request, int $id)
    {
        $user = Auth::user();

        if (!$this->authorizer->canManageTypes($user)) {
            return response()->json(['message' => 'Apenas gestores podem excluir tipos.'], 403);
        }

        $type = DocumentType::fromCompany($user->company_id)->find($id);

        if (!$type) {
            return response()->json(['message' => 'Tipo não encontrado.'], 404);
        }

        // Seeded types and types in use are deactivated, never deleted —
        // documents reference them and their labels appear on issued papers.
        if ($type->is_system || $type->documents()->exists()) {
            $type->update(['is_active' => false]);

            return response()->json(['message' => 'Tipo desativado.']);
        }

        $type->delete();

        return response()->json(['message' => 'Tipo excluído.']);
    }

    /* ============================================================
     | Custom variables
     ============================================================ */

    public function variables(Request $request)
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        return response()->json([
            'data' => DocumentVariable::fromCompany($user->company_id)->orderBy('key')->get()
                ->map(fn (DocumentVariable $v) => $v->toPublicArray())
                ->values(),
        ]);
    }

    public function storeVariable(Request $request)
    {
        $user = Auth::user();

        if (!$this->authorizer->canManageTypes($user)) {
            return response()->json(['message' => 'Apenas gestores podem criar variáveis.'], 403);
        }

        $validated = $request->validate([
            // Restricted to what the template language can address.
            'key'         => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label'       => ['required', 'string', 'max:255'],
            'value'       => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'key.regex' => 'A chave deve começar com letra minúscula e conter apenas letras, números e underscore.',
        ]);

        $exists = DocumentVariable::fromCompany($user->company_id)
            ->where('key', $validated['key'])->exists();

        if ($exists) {
            return response()->json(['message' => 'Já existe uma variável com esta chave.'], 422);
        }

        $variable = DocumentVariable::create(array_merge($validated, [
            'company_id' => $user->company_id,
        ]));

        return response()->json([
            'message' => 'Variável criada.',
            'data'    => $variable->toPublicArray(),
        ], 201);
    }

    public function updateVariable(Request $request, int $id)
    {
        $user = Auth::user();

        if (!$this->authorizer->canManageTypes($user)) {
            return response()->json(['message' => 'Apenas gestores podem editar variáveis.'], 403);
        }

        $variable = DocumentVariable::fromCompany($user->company_id)->find($id);

        if (!$variable) {
            return response()->json(['message' => 'Variável não encontrada.'], 404);
        }

        // The key is immutable: templates already reference it by name, and
        // renaming would silently blank them on the next generation.
        $variable->update($request->validate([
            'label'       => ['sometimes', 'string', 'max:255'],
            'value'       => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]));

        return response()->json([
            'message' => 'Variável atualizada.',
            'data'    => $variable->fresh()->toPublicArray(),
        ]);
    }

    public function destroyVariable(Request $request, int $id)
    {
        $user = Auth::user();

        if (!$this->authorizer->canManageTypes($user)) {
            return response()->json(['message' => 'Apenas gestores podem excluir variáveis.'], 403);
        }

        $variable = DocumentVariable::fromCompany($user->company_id)->find($id);

        if (!$variable) {
            return response()->json(['message' => 'Variável não encontrada.'], 404);
        }

        $variable->delete();

        return response()->json(['message' => 'Variável excluída.']);
    }

    /* ============================================================
     | Variable catalogue
     ============================================================ */

    /** Everything an author may insert — drives the editor's picker. */
    public function variableRegistry(Request $request)
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        return response()->json(['groups' => $this->registry->catalog($user->company_id)]);
    }

    private function uniqueKey(int $companyId, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'tipo';
        $key  = $base;
        $n    = 2;

        while (DocumentType::fromCompany($companyId)->where('key', $key)->exists()) {
            $key = "{$base}_{$n}";
            $n++;
        }

        return $key;
    }
}

<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * WHO MAY DO WHAT TO A DOCUMENT
 *
 * Every authorization decision in the module goes through this class, and
 * nothing else makes one. That single seam is the point.
 *
 * The CRM once had a table-driven permission system — `permissions` and
 * `role_permissions`, with a `permission:<key>` middleware — but the tables
 * were dropped in `2026_04_16_000001_drop_permissions_and_role_permissions_tables`,
 * leaving the middleware and models orphaned against tables that no longer
 * exist. Reviving that subsystem is its own piece of work, not something to
 * smuggle in behind a contracts feature.
 *
 * So the rules below are expressed against the roles the application actually
 * uses today (`admin`, `manager`, `agent`, via User::isAdmin()/isManager()),
 * and enforced server-side — the UI is not a security boundary for a legal
 * document. When a permission table returns, only the bodies of these methods
 * change; no controller, route or frontend does.
 *
 * The intended shape, for whoever wires that up:
 *
 *   documents.view · documents.create · documents.edit · documents.send
 *   documents.approve · documents.cancel · documents.delete
 *   documents.templates.manage · documents.types.manage
 */
class DocumentAuthorizer
{
    /** Anyone in the company may read its documents. */
    public function canView(User $user, Document $document): bool
    {
        return $this->sameCompany($user, $document);
    }

    public function canCreate(User $user): bool
    {
        return $user->company_id !== null;
    }

    /**
     * Editing is the owner's or a manager's. An agent who did not create a
     * contract has no business rewriting its terms.
     */
    public function canEdit(User $user, Document $document): bool
    {
        return $this->sameCompany($user, $document)
            && ($this->isManager($user) || $this->owns($user, $document));
    }

    /** Sending a contract to a counterparty commits the company. */
    public function canSend(User $user, Document $document): bool
    {
        return $this->canEdit($user, $document);
    }

    /**
     * Approval is deliberately *not* grantable to the document's own owner —
     * an approval step whose author can approve their own work is decoration.
     * (Behaviour lands in phase 2; the rule is stated here now so it is not
     * quietly invented later.)
     */
    public function canApprove(User $user, Document $document): bool
    {
        return $this->sameCompany($user, $document)
            && $this->isManager($user)
            && !$this->owns($user, $document);
    }

    public function canCancel(User $user, Document $document): bool
    {
        return $this->canEdit($user, $document);
    }

    /** Only drafts are ever deletable, and only by a manager or the owner. */
    public function canDelete(User $user, Document $document): bool
    {
        return $this->canEdit($user, $document) && $document->isEditable();
    }

    /** Templates are legal's instrument; agents may use them, not write them. */
    public function canManageTemplates(User $user): bool
    {
        return $this->isManager($user);
    }

    public function canManageTypes(User $user): bool
    {
        return $this->isManager($user);
    }

    /* ============================
     | Enforcement
     ============================ */

    /**
     * @throws AuthorizationException
     */
    public function authorize(bool $allowed, string $message = 'Você não tem permissão para esta ação.'): void
    {
        if (!$allowed) {
            throw new AuthorizationException($message);
        }
    }

    private function sameCompany(User $user, Document $document): bool
    {
        return $user->company_id !== null
            && (int) $user->company_id === (int) $document->company_id;
    }

    private function owns(User $user, Document $document): bool
    {
        return (int) $user->id === (int) $document->owner_id
            || (int) $user->id === (int) $document->created_by;
    }

    /** Admin or manager — the same test `User::canManageUsers()` already uses. */
    private function isManager(User $user): bool
    {
        return $user->isAdmin() || $user->isManager();
    }
}

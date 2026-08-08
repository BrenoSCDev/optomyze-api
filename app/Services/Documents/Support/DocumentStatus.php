<?php

namespace App\Services\Documents\Support;

/**
 * The document lifecycle.
 *
 * Wider than a signature's status because a document exists before anyone is
 * asked to sign it and after everyone has: it is drafted, may need internal
 * approval, is sent, collects signatures one at a time, and eventually lands
 * somewhere terminal. The Kanban board is this list.
 */
final class DocumentStatus
{
    /** Being written. Editable, regenerable, deletable. */
    public const DRAFT = 'draft';

    /** Waiting on an internal approver. (Behaviour: phase 2.) */
    public const PENDING_APPROVAL = 'pending_approval';

    /** Approved and generated; nobody outside the company has seen it yet. */
    public const READY_TO_SEND = 'ready_to_send';

    /** With the signature provider, nobody has signed. */
    public const WAITING_SIGNATURE = 'waiting_signature';

    /** At least one signer has signed, at least one has not. */
    public const PARTIALLY_SIGNED = 'partially_signed';

    /** Everyone signed and the provider finalised it. */
    public const SIGNED = 'signed';

    /** A signer declined. */
    public const REJECTED = 'rejected';

    /** The signing deadline passed without completion. */
    public const EXPIRED = 'expired';

    /** Withdrawn before completion. */
    public const CANCELLED = 'cancelled';

    /** Filed away; out of the working board, still readable. */
    public const ARCHIVED = 'archived';

    public const ALL = [
        self::DRAFT,
        self::PENDING_APPROVAL,
        self::READY_TO_SEND,
        self::WAITING_SIGNATURE,
        self::PARTIALLY_SIGNED,
        self::SIGNED,
        self::REJECTED,
        self::EXPIRED,
        self::CANCELLED,
        self::ARCHIVED,
    ];

    /** The Kanban columns, in board order. Terminal-but-interesting included. */
    public const BOARD = [
        self::DRAFT,
        self::PENDING_APPROVAL,
        self::READY_TO_SEND,
        self::WAITING_SIGNATURE,
        self::PARTIALLY_SIGNED,
        self::SIGNED,
    ];

    /** Nothing further will happen on its own. */
    public const TERMINAL = [
        self::SIGNED,
        self::REJECTED,
        self::EXPIRED,
        self::CANCELLED,
        self::ARCHIVED,
    ];

    /** The provider owns it; our edits would desynchronise. */
    public const WITH_PROVIDER = [
        self::WAITING_SIGNATURE,
        self::PARTIALLY_SIGNED,
    ];

    /** States where the body may still be rewritten or regenerated. */
    public const EDITABLE = [
        self::DRAFT,
        self::PENDING_APPROVAL,
        self::READY_TO_SEND,
    ];

    /**
     * Statuses a user may drag a card into on the board.
     *
     * Deliberately narrow: everything from WAITING_SIGNATURE onward is set by
     * the provider's webhooks, and letting someone drag a card to "Signed"
     * would put a lie on a legal record.
     */
    public const MANUALLY_SETTABLE = [
        self::DRAFT,
        self::PENDING_APPROVAL,
        self::READY_TO_SEND,
        self::CANCELLED,
        self::ARCHIVED,
    ];

    public static function isTerminal(?string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function isEditable(?string $status): bool
    {
        return in_array($status, self::EDITABLE, true);
    }

    public static function isWithProvider(?string $status): bool
    {
        return in_array($status, self::WITH_PROVIDER, true);
    }
}

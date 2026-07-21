<?php

namespace App\Enums\Files;

enum ManagedFileAction: string
{
    case UploadStaged = 'UPLOAD_STAGED';
    case UploadRejected = 'UPLOAD_REJECTED';
    case Registered = 'REGISTERED';
    case Linked = 'LINKED';
    case Unlinked = 'UNLINKED';
    case Archived = 'ARCHIVED';
    case Trashed = 'TRASHED';
    case Restored = 'RESTORED';
    case Purged = 'PURGED';
    case Quarantined = 'QUARANTINED';
    case ReleasedFromQuarantine = 'RELEASED_FROM_QUARANTINE';
    case IntegrityChecked = 'INTEGRITY_CHECKED';
    case LegacyFallbackUsed = 'LEGACY_FALLBACK_USED';
    case LegacyObserved = 'LEGACY_OBSERVED';
    case ReconciliationCompleted = 'RECONCILIATION_COMPLETED';
}

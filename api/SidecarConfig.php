<?php
// Sidecar 常量与权限

class SidecarConfig {
    const PERM_PUBLIC = 'public';
    const PERM_GUEST = 'guest_verified';
    const PERM_STAFF = 'staff_only';
    const PERM_ADMIN = 'admin_only';

    const SEMANTIC_THRESHOLD = 0.52;
    const VECTOR_BATCH_SIZE = 50;

    public static function chunkEligibleStatuses(): array {
        return ['normal', 'pending_review'];
    }
}

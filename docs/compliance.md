# Compliance Playbook

This document outlines operational and technical controls for CCPA and GDPR readiness. It is a baseline and must be reviewed with legal counsel before production use.

## Common Principles
- Data minimization and purpose limitation.
- Clear consent records with policy versions.
- Short retention for IP and device signals.
- Audit trail for all privacy-related actions.

## CCPA
- Do not sell or share personal data for advertising.
- Provide clear opt-out controls and honor in all services.
- Support access and deletion requests with a 45-day SLA.
- Maintain a record of requests and outcomes.

## GDPR
- Lawful basis recorded per data category.
- Right to access, rectification, and erasure.
- Data portability with export in structured format.
- Data protection impact assessment for new features.

## Data Subject Requests (DSR)
- Requests logged in Compliance service or Auth/Accounts.
- Automated export job with secure, time-limited download link.
- Deletion requests perform soft delete, then purge within policy window.

## Data Retention
- IP addresses: captured only at post/report creation time (never at HTTP level), encrypted
  at rest (XChaCha20-Poly1305), admin-decryptable for moderation and legal compliance,
  auto-deleted per retention schedule (30 days for posts, 90 days for reports).
  A deterministic SHA-256 hash is stored alongside for abuse-filtering lookups.
- Moderation logs: retained per policy for safety and legal defense.
- Media: retained until deletion or expiry policy triggers.

## Consent and Policy Versioning
- Store consent with policy version and timestamp.
- Notify users of material policy changes and re-consent when required.

## Security Controls
- Role-based access for moderation and compliance operations.
- Encryption at rest for sensitive columns (PII encrypted via XChaCha20-Poly1305).
- Immutable audit log for DSRs and moderation.
- PII decryption audit trail (`pii_access_log`).
- Automated IP retention with audit logging (`pii_retention_log`).

## Implementation References
- **Data Inventory:** `docs/DATA_INVENTORY.md`
- **Privacy/ToS Disclosure:** `docs/PRIVACY_TOS_DISCLOSURE.md`
- **SFS Workflow:** `docs/SFS_ESCALATION_PLAYBOOK.md` (Phase 4)
- **Encryption Service:** `services/*/app/Service/PiiEncryptionService.php`
- **Retention Service:** `services/*/app/Service/IpRetentionService.php`
- **Cron Command:** `services/*/app/Command/PiiCleanupCommand.php`
- **Migration:** `db/migrations/20260220000001_pii_encryption_retention.sql`

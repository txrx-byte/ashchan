# Compliance Playbook

This document outlines operational and technical controls for COPPA, CCPA, and GDPR readiness. It is a baseline and must be reviewed with legal counsel before production use.

## Common Principles
- Data minimization and purpose limitation.
- Clear consent records with policy versions.
- Short retention for IP and device signals.
- Audit trail for all privacy-related actions.

## COPPA
- Age gate at account creation and before collecting personal data.
- Parental consent workflow for under-13 accounts.
- Default to anonymous usage when possible.
- Retain age verification only as a token, not full details.

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
- IP and device signals: short-lived, hashed, and rotated.
- Moderation logs: retained per policy for safety and legal defense.
- Media: retained until deletion or expiry policy triggers.

## Consent and Policy Versioning
- Store consent with policy version and timestamp.
- Notify users of material policy changes and re-consent when required.

## Security Controls
- Role-based access for moderation and compliance operations.
- Encryption at rest for sensitive columns.
- Immutable audit log for DSRs and moderation.

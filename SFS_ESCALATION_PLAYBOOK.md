# SFS Escalation & Data Enrichment Playbook

## Executive Summary
This document outlines the strategic roadmap for enhancing Ashchan's moderation capabilities by integrating optional StopForumSpam (SFS) reporting. The plan necessitates a shift from irreversible IP hashing to a reversible storage model (initially raw, later encrypted), the introduction of high-fidelity fingerprinting (JA4), and a "Human-in-the-Loop" approval queue for SFS submissions.

## Phase 1: De-anonymization (Schema Modernization)
**Objective**: Remove irreversible IP hashing to enable SFS reporting and granular IP bans.
**Status**: [ ] Pending

### 1.1 Database Schema Changes
*   **Service**: `boards-threads-posts`
    *   [ ] Create migration to add `ip_address` (INET/VARCHAR) to `posts` table.
    *   [ ] (Optional) Drop `ip_hash` column or keep for legacy verification.
*   **Service**: `moderation-anti-spam`
    *   [ ] Create migration to add `ip_address` to `reports` and `bans` tables.

### 1.2 Application Logic Updates
*   **Service**: `api-gateway`
    *   [ ] Update `GatewayController` / `FrontendController` to forward raw `X-Real-IP` or `Remote-Addr` to backend services (already handling `X-Forwarded-For`).
*   **Service**: `boards-threads-posts`
    *   [ ] Update `BoardService::createPost` and `createThread` to persist raw IP address instead of calculating SHA256 hash.
    *   [ ] Update `ThreadController` to accept `ip_address` from request attributes.
*   **Service**: `moderation-anti-spam`
    *   [ ] Update `ModerationService::createReport` to store raw IP.

---

## Phase 2: The Pending Reports Queue (Data Lake)
**Objective**: Create a holding area for SFS reports, decoupling the "flagging" action from the "submission" action.
**Status**: [ ] Pending

### 2.1 Database Schema (`moderation-anti-spam`)
*   [ ] Create table `sfs_pending_reports`:
    *   `id` (BIGSERIAL)
    *   `post_id` (BIGINT)
    *   `board_slug` (VARCHAR)
    *   `ip_address` (VARCHAR)
    *   `ja4_fingerprint` (VARCHAR, nullable) - *Forward compatibility*
    *   `post_content` (TEXT)
    *   `evidence_snapshot` (JSONB) - Captures headers, UA, etc. at time of report.
    *   `reporter_id` (VARCHAR) - Staff member who flagged it.
    *   `status` (ENUM: 'pending', 'approved', 'rejected')
    *   `created_at` (TIMESTAMP)

### 2.2 API Endpoints
*   [ ] `POST /api/v1/internal/sfs/queue`: Internal endpoint for other services to push report data.
*   [ ] `GET /api/v1/admin/sfs/queue`: List pending reports for Admins/Managers.
*   [ ] `POST /api/v1/admin/sfs/queue/{id}/approve`: Decrypts (if needed) and submits to SFS API.
*   [ ] `POST /api/v1/admin/sfs/queue/{id}/reject`: Discards the report.

---

## Phase 3: JA4 Fingerprinting (Data Enrichment)
**Objective**: Implement robust client fingerprinting to identify non-browser traffic and persistent spammers.
**Status**: [ ] Pending

### 3.1 Infrastructure / Gateway
*   **Requirement**: JA4 requires TLS Client Hello parameters.
*   [ ] **Investigation**: Determine if the upstream Load Balancer / Ingress can generate `X-JA4-Fingerprint`.
    *   *If yes*: Pass header to `api-gateway`.
    *   *If no*: Investigate using a specialized sidecar (e.g., HAProxy or a Go-based proxy) to terminate TLS and inspect packets before they hit Hyperf.
*   [ ] **Fallback**: Implement JA3 or simple header fingerprinting if JA4 is infrastructure-prohibitive.

### 3.2 Application Integration
*   [ ] Add `ja4_fingerprint` column to `posts` table (`boards-threads-posts`).
*   [ ] Capture `X-JA4-Fingerprint` in `api-gateway` and forward to backends.

---

## Phase 4: Privacy Hardening (Encryption at Rest)
**Objective**: Re-introduce privacy by encrypting IP addresses in the database, ensuring they are only visible when necessary (e.g., SFS submission).
**Status**: [ ] Future

### 4.1 Encryption Layer
*   [ ] Implement `Sodium` or `OpenSSL` encryption in `App\Model\Post` and `App\Model\Report`.
*   [ ] Key Management: Store encryption keys securely (Vault/Secrets Manager), not in code.
*   [ ] Database columns: Rename `ip_address` to `ip_encrypted` (BYTEA).

### 4.2 Decryption "On-the-Fly"
*   [ ] Update `StopForumSpamService` to accept encrypted payload, decrypt in memory, and send to SFS.
*   [ ] Ensure Admins have "View IP" permission which triggers decryption audit log.

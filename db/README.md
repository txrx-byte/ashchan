# Database Schemas

This directory contains PostgreSQL migrations and schema definitions for all services.

## Conventions
- Migrations versioned with timestamp.
- Separate schema namespace per service (optional; depends on deployment).
- Foreign keys only within service boundaries.

## Schema Ownership
- **Auth/Accounts:** users, sessions, consents, deletion_requests.
- **Boards/Threads/Posts:** boards, threads, posts.
- **Media/Uploads:** media_objects, media_metadata.
- **Search/Indexing:** search_documents (or external index).
- **Moderation/Anti-spam:** reports, decisions, risk_scores.

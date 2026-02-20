# Anti-Spam & Moderation

## Layers of Defense
1. **StopForumSpam (Layer 0a):** IP/email/username reputation checks against the SFS database.
2. **Spur IP Intelligence (Layer 0b):** VPN, residential proxy, and bot detection via [Spur Context API](https://docs.spur.us/context-api). Togglable by admins at runtime. See [SPUR_IMPLEMENTATION.md](SPUR_IMPLEMENTATION.md).
3. **Rate limiting:** per-IP, per-user, per-board, per-thread.
4. **Content fingerprinting:** near-duplicate detection.
5. **Risk scoring:** heuristic + ML, integrated with device/IP reputation.
6. **Quarantine queue:** high-risk posts require human review.
7. **CAPTCHA escalation:** triggered on suspicious behavior.
8. **Honeyboard traps:** fake boards for spambot detection.

## Moderation Workflow
- Automated scoring assigns priority.
- Queue for moderator review.
- Decisions logged with reason and timestamp.
- Appeals workflow with versioned decision history.

## Shadow Banning
- Transparent to user but hidden from public views.
- Gradual cooldown and de-escalation path.

## IP and ASN Reputation
- Shared reputation store (Redis).
- Thresholds for temporary blocks and escalation.
- **Spur integration** enriches IP data with VPN/proxy/datacenter classification.
- **StopForumSpam** checks IP, email, and username against known spammer databases.

## Content Similarity
- Hash content + normalize for fuzzy match.
- Cross-board detection for repeat spam.

## Admin Feature Toggles
- Runtime-configurable via `site_settings` database table.
- Toggle Spur, SFS, and other features without service restarts.
- All changes are audit-logged with timestamp, actor, and reason.
- Admin API: `GET/PUT /api/v1/admin/settings/{key}`

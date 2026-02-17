# Anti-Spam & Moderation

## Layers of Defense
1. **Rate limiting:** per-IP, per-user, per-board, per-thread.
2. **Content fingerprinting:** near-duplicate detection.
3. **Risk scoring:** heuristic + ML, integrated with device/IP reputation.
4. **Quarantine queue:** high-risk posts require human review.
5. **CAPTCHA escalation:** triggered on suspicious behavior.
6. **Honeyboard traps:** fake boards for spambot detection.

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

## Content Similarity
- Hash content + normalize for fuzzy match.
- Cross-board detection for repeat spam.

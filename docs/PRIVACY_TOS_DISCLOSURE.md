# Privacy Policy & Terms of Service — Data Handling Disclosure

**Last Updated:** 2026-02-20
**Policy Version:** 1.0.0

---

## Terms of Service — Data Collection & Sharing Disclosure

The following language must be included in the Ashchan Terms of Service. It discloses
the types of data collected, how it is protected, and the circumstances under which it
may be shared with third parties.

---

### Section: Data Collection

> **What We Collect**
>
> When you use Ashchan, we automatically collect limited technical data necessary
> for the operation, security, and moderation of the platform:
>
> - **IP Address:** Collected **only** when you create a post, thread, or report.
>   Your IP is **not** recorded in HTTP access logs or any other server-level logging.
>   It is encrypted at rest using industry-standard authenticated encryption
>   (XChaCha20-Poly1305) and can only be decrypted by authorized administrators
>   for moderation or legal compliance purposes. Automatically and irreversibly
>   deleted after **30 days**.
>
> - **Email Address (optional):** Only collected if you voluntarily enter it in the
>   email field. Used for "sage" and "noko" functionality. Encrypted at rest.
>   Automatically deleted after **30 days**.
>
> - **Browser Metadata:** User-Agent string and connection metadata may be collected
>   for rate limiting and abuse detection. Retained for up to **24 hours**.
>
> - **Country Code:** Derived from your IP address via GeoIP lookup. Not considered
>   PII on its own. May be displayed on posts (board-dependent).
>
> **What We Do NOT Collect**
>
> - We do not use tracking cookies, analytics, or advertising pixels.
> - We do not create user profiles or behavioral models.
> - We do not sell or share your data for advertising purposes.

---

### Section: Data Encryption

> **Encryption at Rest**
>
> All personally identifiable information (PII) — including IP addresses, email
> addresses, and any data that could directly identify you — is encrypted before
> being stored in our database. This means that even in the event of a database
> breach, your personal data cannot be read without the encryption keys.
>
> We use **XChaCha20-Poly1305** authenticated encryption, which is a modern
> AEAD cipher recommended by the security community.

---

### Section: Data Retention & Automatic Deletion

> **Retention Periods**
>
> We retain personal data only for as long as necessary to fulfill the purposes
> for which it was collected:
>
> | Data Type | Retention Period | What Happens After |
> |---|---|---|
> | Post IP addresses | 30 days | Permanently and irreversibly deleted |
> | Email addresses | 30 days | Permanently and irreversibly deleted |
> | Report IPs | 90 days | Permanently and irreversibly deleted |
> | Ban records (IPs) | Ban duration + 30 days | IP data permanently deleted |
> | Rate limiting logs | 24 hours | Entire record permanently deleted |
> | Access logs | 7 days | Rotated and overwritten (no IPs stored) |
> | Moderation decisions | 1 year | Permanently deleted |
>
> Deletion is performed automatically by scheduled system processes. No manual
> intervention is required, and no human reviews the data before deletion.

---

### Section: Third-Party Data Sharing — StopForumSpam

> **StopForumSpam (SFS) Disclosure**
>
> To protect the community from spam and abuse, Ashchan integrates with
> **StopForumSpam** (https://www.stopforumspam.com), a publicly accessible
> anti-spam database.
>
> **How it works:**
>
> 1. When you create a post, your IP address may be checked against the SFS
>    database to determine if it has been previously reported as a spam source.
>    This check sends your IP address to SFS over an encrypted (HTTPS) connection.
>
> 2. **If your post is identified as spam by a moderator**, a site administrator
>    may submit a report to StopForumSpam that includes:
>    - Your **IP address** (decrypted from our encrypted storage specifically for
>      this purpose)
>    - Your **username** (as displayed on the post, typically "Anonymous")
>    - The **content of your post** (as evidence)
>
> 3. Once submitted to StopForumSpam, this data becomes **part of a public
>    anti-spam database** and is subject to StopForumSpam's own privacy policy
>    (https://www.stopforumspam.com/privacy).
>
> **Important:** SFS submission is NOT automatic. It requires explicit, manual
> approval by a site administrator. Your encrypted IP address must be actively
> decrypted by an administrator for this specific purpose. An audit trail is
> maintained for all such actions.
>
> **By using this platform, you acknowledge and consent to the possibility that
> your IP address and associated post data may be reported to StopForumSpam if
> your activity is determined to constitute spam or abuse.** If you do not consent
> to this, you should not use this platform.

---

### Section: Your Rights (GDPR/CCPA)

> **Your Data Rights**
>
> Depending on your jurisdiction, you may have the following rights regarding
> your personal data:
>
> - **Right to Access:** You can request a copy of the data we hold about you.
> - **Right to Erasure ("Right to be Forgotten"):** You can request deletion of
>   your data. Note that IP data is automatically deleted per our retention
>   schedule.
> - **Right to Rectification:** You can request correction of inaccurate data.
> - **Right to Data Portability:** You can request your data in a structured,
>   machine-readable format.
> - **Right to Object:** You can object to processing of your data for specific
>   purposes, including SFS reporting.
>
> To exercise these rights, contact the site administrator. We will respond
> within 30 days (GDPR) or 45 days (CCPA).
>
> **Automated Decision-Making:** We use automated spam scoring during post
> creation. If your post is blocked by automated anti-spam measures, you may
> contact the site administrator to request manual review.

---

### Section: Legal Basis for Processing (GDPR)

> We process your personal data based on:
>
> - **Legitimate Interest (Art. 6(1)(f)):** Abuse prevention, spam mitigation,
>   platform security, and community safety.
> - **Consent:** For optional data (email field) and acknowledgment of SFS
>   reporting as described above.
> - **Legal Obligation:** Where required by law enforcement requests or court
>   orders.

---

### Section: Data Breach Notification

> In the event of a data breach affecting your personal data, we will:
>
> - Notify the relevant supervisory authority within 72 hours (GDPR requirement)
> - Notify affected users if the breach poses a high risk to their rights
> - Publish a notice on the platform
>
> Due to our encryption-at-rest policy, a database breach alone would not expose
> your personal data in readable form.

---

## Implementation Notes

This disclosure should be:
1. Linked from the site footer on every page
2. Shown during any registration/account creation flow
3. Referenced in the post submission form (brief notice + link)
4. Versioned — changes require incrementing the policy version and re-consent

### Suggested Post Form Notice

```html
<p class="privacy-notice">
  By submitting this post, you agree to our 
  <a href="/privacy" target="_blank">Privacy Policy</a>. 
  Your IP address is encrypted at rest and automatically deleted after 30 days.
  It is not recorded in server access logs.
  Spam may be reported to 
  <a href="https://www.stopforumspam.com" target="_blank">StopForumSpam</a>.
</p>
```

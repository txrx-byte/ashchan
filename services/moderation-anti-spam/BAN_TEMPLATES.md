# Ban Templates - Ported from OpenYotsuba

## Source
These are the **EXACT** ban templates used on 4chan, ported from:
`/home/abrookstgz/OpenYotsuba/board/setup.php` (lines 168-176)

## Default Templates

### 1. Child Pornography (Explicit Image)
- **Rule:** global1
- **Type:** zonly (unappealable)
- **Length:** Permanent (indefinite)
- **Public Reason:** "Child pornography"
- **Action:** quarantine + delete all + blacklist image
- **Access:** Janitor
- **Exclude:** __nofile__ (requires file)

### 2. Child Pornography (Non-Explicit Image)
- **Rule:** global1
- **Type:** zonly (unappealable)
- **Length:** Permanent (indefinite)
- **Public Reason:** "Child pornography"
- **Action:** revokepass_illegal + delete all + blacklist image
- **Access:** Janitor
- **Exclude:** __nofile__ (requires file)

### 3. Child Pornography (Links)
- **Rule:** global1
- **Type:** zonly (unappealable)
- **Length:** Permanent (indefinite)
- **Public Reason:** "Child pornography"
- **Action:** revokepass_illegal + delete all
- **Access:** Janitor

### 4. Illegal Content
- **Rule:** global1
- **Type:** zonly (unappealable)
- **Length:** Permanent (indefinite)
- **Public Reason:** "You will not upload, post, discuss, request, or link to anything that violates applicable law."
- **Action:** revokepass_illegal + delete all
- **Access:** Janitor

### 5. NSFW on Blue Board
- **Rule:** global2
- **Type:** global
- **Length:** 1 day
- **Public Reason:** "All boards with the Yotsuba B style as the default are to be considered \"work safe\". Violators may be temporarily banned and their posts removed. Note: Spoilered pornography or other \"not safe for work\" content is NOT allowed."
- **Action:** delete file only
- **Access:** Janitor
- **Can Warn:** Yes
- **Public Ban:** Yes
- **Exclude:** __nws__ (not on NSFW boards)

### 6. False Reports
- **Rule:** global3
- **Type:** global
- **Length:** Warning (0 days)
- **Public Reason:** "Submitting false or misclassified reports, or otherwise abusing the reporting system may result in a ban."
- **Access:** Janitor
- **Can Warn:** Yes

### 7. Ban Evasion
- **Rule:** global4
- **Type:** global
- **Length:** Permanent (indefinite)
- **Public Reason:** "Evading your ban will result in a permanent one. Instead, wait and appeal it!"
- **Action:** Delete all by IP
- **Access:** Janitor
- **Can Warn:** Yes
- **Public Ban:** Yes

### 8. Spam
- **Rule:** global5
- **Type:** global
- **Length:** 1 day
- **Public Reason:** "No spamming or flooding of any kind. No intentionally evading spam or post filters."
- **Action:** Delete all by IP
- **Access:** Janitor
- **Can Warn:** Yes
- **Public Ban:** Yes

### 9. Advertising
- **Rule:** global6
- **Type:** global
- **Length:** 1 day
- **Public Reason:** "Advertising (all forms) is not welcome—this includes any type of referral linking, \"offers\", soliciting, begging, stream threads, etc."
- **Action:** Delete all by IP
- **Access:** Janitor
- **Can Warn:** Yes

## Rule Categories

The templates use the following rule categories:

| Rule ID | Description |
|---------|-------------|
| global1 | Illegal content (CP, illegal materials) |
| global2 | NSFW content on worksafe boards |
| global3 | Report system abuse |
| global4 | Ban evasion |
| global5 | Spam/flooding |
| global6 | Advertising |

## Special Exclude Patterns

- `__nofile__` - Template only applies to posts WITH images
- `__nws__` - Template only applies to NSFW boards (not worksafe)

## Actions

| Action | Description |
|--------|-------------|
| (empty) | No additional action |
| `quarantine` | Quarantine the file for review |
| `revokepass_illegal` | Revoke 4chan Pass privileges |
| `delfile` | Delete only the image |
| `delall` | Delete all posts by IP |

## Usage

```php
// Get all active templates
$templates = BanTemplate::query()
    ->where('active', 1)
    ->orderBy('rule')
    ->get();

// Get templates for a specific rule category
$cpTemplates = BanTemplate::query()
    ->where('rule', 'global1')
    ->where('active', 1)
    ->get();

// Check if template requires a file
if ($template->exclude === '__nofile__') {
    // Post must have an image
}

// Check if template is for worksafe boards only
if ($template->exclude === '__nws__') {
    // Only applies to worksafe boards
}
```

## Seeding

```bash
php bin/hyperf.php db:seed BanTemplateSeeder
```

This will insert the exact 9 templates from 4chan's setup.php.

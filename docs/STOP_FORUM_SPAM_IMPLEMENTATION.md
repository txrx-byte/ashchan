# StopForumSpam & Frontend Mod Tools Implementation Plan

## 1. Overview
This document outlines the plan to integrate the **StopForumSpam (SFS) API** into Ashchan's backend and port the **OpenYotsuba frontend moderation tools** to Ashchan's frontend. The goal is to automate spam detection and provide inline moderation capabilities (ban, delete, report) for authenticated staff members.

## 2. Backend: StopForumSpam Integration
**Service:** `services/moderation-anti-spam`

### 2.1. StopForumSpamService
Create a new service class `App\Service\StopForumSpamService` to handle API interactions.

*   **Dependencies:** HTTP Client (Guzzle or Hyperf HTTP Client).
*   **Configuration:** API Key (for reporting), Thresholds (confidence score).
*   **Methods:**
    *   `check(string $ip, string $email = null, string $username = null): bool`: Queries SFS API. Returns `true` if the user is flagged as a spammer.
    *   `report(string $ip, string $email, string $username, string $evidence): void`: Submits a spam report to SFS (requires API key).

### 2.2. Integration Points
*   **Post Creation:**
    *   In `services/api-gateway` or `services/boards-threads-posts`, before persisting a post, call `StopForumSpamService->check()`.
    *   If `check()` returns `true`, reject the post with a 403 Forbidden error.
    *   *Note:* Ensure this call has a short timeout to prevent degrading posting performance.
*   **User Registration (if applicable):**
    *   Check email and IP during account creation.
*   **Ban Actions:**
    *   When a moderator bans a user, provide an option (checkbox) to "Report to StopForumSpam".
    *   Trigger `StopForumSpamService->report()` asynchronously (via Event or Job queue) to avoid blocking the ban action.

### 2.3. API Endpoints
New internal endpoints in `services/moderation-anti-spam` (reachable by other services):
*   `POST /internal/spam/check`: Accepts IP/Email/Name. Returns spam status.
*   `POST /internal/spam/report`: Accepts IP/Email/Name/Evidence. Queues report.

---

## 3. Frontend: Mod Tools Port
**Source:** `../OpenYotsuba/js/mod.js`, `janitor.js`
**Target:** `frontend/static/js/mod.js`

### 3.1. Core Logic (mod.js)
We will create a new `mod.js` specifically for Ashchan, adapted from OpenYotsuba's logic.

*   **Initialization:**
    *   The script should only initialize if it detects it is running in an authenticated staff context (checked via API or DOM flag).
*   **UI Components:**
    *   **Post Menu Injection:** Hook into existing post dropdowns (`.postMenuBtn`) or inject new buttons next to posts.
    *   **Floating Panel:** Recreate the `AdminTools` floating panel for quick access to Reports, Ban Requests, and Notes.
    *   **Inline Actions:**
        *   **Delete:** `J.deletePost` -> `POST /api/v1/mod/delete`
        *   **Ban:** `J.openBanFrame` -> Open Modal/Popup for Ban Form.
        *   **Report:** `J.reportPost` -> `POST /api/v1/mod/report`
        *   **File MD5:** `J.getFileMD5` -> `GET /api/v1/mod/md5/{post_id}`

### 3.2. Authentication & Security
**Requirement:** "Unauthenticated users should never have access to mod tools."

*   **Server-Side Injection:**
    *   In the rendering engine (likely `api-gateway` acting as BFF or a dedicated frontend service), check the user's role.
    *   Only include `<script src="/static/js/mod.js"></script>` in the HTML if the user is authenticated as staff.
    *   Example (Twig):
        ```twig
        {% if user.is_staff %}
            <script src="/static/js/mod.js" defer></script>
            <link rel="stylesheet" href="/static/css/mod.css">
        {% endif %}
        ```
*   **API Security:**
    *   All endpoints called by `mod.js` (e.g., `/api/v1/mod/*`) must enforce strict JWT/Session authentication and Role-Based Access Control (RBAC) at the Gateway level.

### 3.3. Implementation Steps
1.  **Analyze Templates:** `frontend/templates/thread.html` uses `postInfo` and `postMenuBtn` classes. The new `mod.js` must target these selectors.
2.  **Create JS:**
    *   Rewrite `J.parsePost` to find `div.postInfo` and append the Moderator Control Panel (Delete/Ban buttons).
    *   Implement `J.deletePost` using Ashchan's API structure (`fetch` instead of `XMLHttpRequest`).
    *   Implement "Ban" modal to load a partial or an iframe (Ashchan style preference needed).
3.  **Styling:**
    *   Port relevant CSS from OpenYotsuba (floating panel, button styles) to `frontend/static/css/mod.css`.

## 4. Work Breakdown

### Phase 1: Backend Support (Completed)
- [x] Implement `StopForumSpamService` (Created in `services/moderation-anti-spam/app/Service/`).
- [x] Create `StopForumSpamController` and routes (Added to `services/moderation-anti-spam`).
- [x] Configure SFS API Key (Added `config/autoload/stopforumspam.php`).

### Phase 2: Frontend Mod Script (Completed)
- [x] Create `frontend/static/js/mod.js` (Implemented Delete, Ban, SFS stub).
- [x] Modify `extension.js` to dispatch `ashchanPostMenuReady` event.
- [x] Implement `Mod.init()` to inject UI elements.

### Phase 3: Integration (Completed)
- [x] Update `layout.html` to conditionally include `mod.js`.
- [x] Update `FrontendController` to pass `is_staff` variable to templates.
- [x] Update `AuthMiddleware` to support "soft auth" for public pages.

### Notes & Limitations
- **IP Storage**: The current system (`boards-threads-posts`) stores IP hashes (`ip_hash`) for privacy. StopForumSpam requires raw IPs. Therefore, "SFS Check" and "Report" for *existing* posts cannot be fully implemented without schema changes to store (encrypted) IPs.
- **SFS Check**: The frontend button currently shows an alert explaining this limitation. SFS checks should be performed synchronously during post creation (Phase 1).

# Moderation System Porting Plan

## Executive Summary

This document outlines the plan to port the comprehensive moderation and janitor features from OpenYotsuba to ashchan. The system includes report management, ban requests, janitor applications, staff management, and admin tools.

## Architecture Overview

### OpenYotsuba Structure
```
OpenYotsuba/
├── reports/           # Report queue system (janitor-facing)
│   ├── ReportQueue.php    # Main controller (2897 lines)
│   ├── access.php         # Access control definitions
│   ├── views/             # Templates
│   └── js/                # Frontend JavaScript
├── admin/             # Admin panel (mod/manager/admin-facing)
│   ├── index.php          # Main admin controller
│   ├── manager/           # Manager tools
│   │   ├── ban_templates.php
│   │   ├── iprangebans.php
│   │   ├── report_categories.php
│   │   └── ...
│   ├── admin/             # Admin-only tools
│   └── views/             # Templates
├── janitorapp.php     # Janitor application system
└── lib/
    ├── admin.php            # Core moderation functions
    └── auth.php             # Authentication
```

### ashchan Target Structure
```
ashchan/
├── services/
│   ├── moderation-anti-spam/    # Enhanced with mod tools
│   │   ├── app/
│   │   │   ├── Controller/
│   │   │   │   ├── ReportController.php
│   │   │   │   ├── BanController.php
│   │   │   │   ├── JanitorAppController.php
│   │   │   │   └── AdminController.php
│   │   │   ├── Model/
│   │   │   │   ├── Report.php
│   │   │   │   ├── Ban.php
│   │   │   │   ├── BanRequest.php
│   │   │   │   ├── JanitorApplication.php
│   │   │   │   ├── BanTemplate.php
│   │   │   │   ├── ReportCategory.php
│   │   │   │   └── ModUser.php
│   │   │   ├── Service/
│   │   │   │   ├── ReportService.php
│   │   │   │   ├── BanService.php
│   │   │   │   ├── AccessService.php
│   │   │   │   └── JanitorAppService.php
│   │   │   └── Middleware/
│   │   │       └── ModAuthMiddleware.php
│   │   └── config/
│   └── api-gateway/
│       ├── app/
│       │   ├── Controller/
│       │   │   ├── ModPanelController.php    # Serve mod UI
│       │   │   └── ReportFrontendController.php
│       │   └── views/
│       │       ├── mod-panel.php
│       │       ├── report-queue.php
│       │       └── ban-requests.php
│       └── views/
├── db/migrations/
│   ├── 008_reports.sql
│   ├── 009_bans.sql
│   ├── 010_ban_requests.sql
│   ├── 011_ban_templates.sql
│   ├── 012_report_categories.sql
│   ├── 013_mod_users.sql
│   ├── 014_janitor_applications.sql
│   └── 015_event_log.sql
└── docs/
    └── moderation-port-plan.md
```

---

## Phase 1: Database Schema (Week 1)

### Migration 008: Reports Table
```sql
CREATE TABLE reports (
    id BIGSERIAL PRIMARY KEY,
    board VARCHAR(20) NOT NULL,
    no BIGINT NOT NULL,              -- Post ID
    resto BIGINT DEFAULT 0,          -- Thread ID (0 if OP)
    report_category INTEGER,
    weight DOUBLE PRECISION DEFAULT 1.0,
    ip INET,
    ts TIMESTAMPTZ DEFAULT NOW(),
    fourpass_id VARCHAR(64),         -- 4chan Pass ID
    pwd VARCHAR(64),                 -- Post password
    req_sig TEXT,                    -- Request signature
    post_ip INET,                    -- Original poster IP
    ws BOOLEAN DEFAULT FALSE,        -- Worksafe flag
    cleared BOOLEAN DEFAULT FALSE,
    cleared_by VARCHAR(64),
    post_json JSONB,                 -- Cached post data
    UNIQUE(board, no, ip)
);

CREATE INDEX idx_reports_board_no ON reports(board, no);
CREATE INDEX idx_reports_cleared ON reports(cleared);
CREATE INDEX idx_reports_ts ON reports(ts DESC);
CREATE INDEX idx_reports_weight ON reports(weight DESC);
```

### Migration 009: Bans Table
```sql
CREATE TABLE banned_users (
    no BIGSERIAL PRIMARY KEY,
    global BOOLEAN DEFAULT FALSE,
    board VARCHAR(20),
    post_num BIGINT,
    template_id INTEGER,
    fourpass_id VARCHAR(64),
    admin VARCHAR(64),
    reason TEXT,
    public_reason TEXT,
    now TIMESTAMPTZ DEFAULT NOW(),
    length TIMESTAMPTZ,
    active BOOLEAN DEFAULT TRUE,
    unbannedon TIMESTAMPTZ,
    unbannedby VARCHAR(64),
    md5 VARCHAR(32),
    password VARCHAR(64),
    zonly BOOLEAN DEFAULT FALSE,     -- Unappealable
    reverse TEXT,                    -- Reverse DNS
    xff TEXT,                        -- X-Forwarded-For
    post_time TIMESTAMPTZ,
    post_json JSONB,
    admin_ip INET,
    tripcode VARCHAR(64),
    host INET NOT NULL,
    rule VARCHAR(64),
    name VARCHAR(255),
    appeal_count INTEGER DEFAULT 0
);

CREATE INDEX idx_bans_host ON banned_users(host);
CREATE INDEX idx_bans_active ON banned_users(active) WHERE active = TRUE;
CREATE INDEX idx_bans_fourpass ON banned_users(fourpass_id) WHERE fourpass_id != '';
CREATE INDEX idx_bans_expires ON banned_users(length) WHERE active = TRUE;
```

### Migration 010: Ban Requests Table
```sql
CREATE TABLE ban_requests (
    id BIGSERIAL PRIMARY KEY,
    tpl_name VARCHAR(255),
    board VARCHAR(20) NOT NULL,
    ts TIMESTAMPTZ DEFAULT NOW(),
    host INET NOT NULL,
    pwd VARCHAR(64),
    global BOOLEAN DEFAULT FALSE,
    warn_req BOOLEAN DEFAULT FALSE,
    ban_template INTEGER,
    reverse TEXT,
    xff TEXT,
    reason TEXT,
    janitor VARCHAR(64) NOT NULL,
    post_json JSONB,
    processed BOOLEAN DEFAULT FALSE,
    processed_by VARCHAR(64),
    processed_at TIMESTAMPTZ,
    approved BOOLEAN
);

CREATE INDEX idx_ban_requests_board ON ban_requests(board);
CREATE INDEX idx_ban_requests_janitor ON ban_requests(janitor);
CREATE INDEX idx_ban_requests_processed ON ban_requests(processed);
```

### Migration 011: Ban Templates Table
```sql
CREATE TABLE ban_templates (
    no SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    rule VARCHAR(64) NOT NULL,       -- e.g., 'global1', 'b2', 'pol3'
    bantype VARCHAR(20) DEFAULT 'local', -- local, global, zonly
    publicreason TEXT,
    privatereason TEXT,
    days INTEGER DEFAULT 0,          -- 0 = warning, -1 = permanent
    banlen INTEGER,                  -- Alternative length field
    level VARCHAR(20) DEFAULT 'janitor', -- janitor, mod, manager, admin
    can_warn BOOLEAN DEFAULT TRUE,
    postban VARCHAR(20),             -- delpost, delfile, delall, move
    postban_arg VARCHAR(255),        -- Argument for postban (e.g., move target)
    action_type VARCHAR(20),         -- quarantine, revokepass_spam, etc.
    save_type VARCHAR(20),           -- everything, json_only
    active BOOLEAN DEFAULT TRUE,
    created_by VARCHAR(64),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ
);

CREATE INDEX idx_templates_rule ON ban_templates(rule);
CREATE INDEX idx_templates_level ON ban_templates(level);
CREATE INDEX idx_templates_active ON ban_templates(active) WHERE active = TRUE;
```

### Migration 012: Report Categories Table
```sql
CREATE TABLE report_categories (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    default_weight DOUBLE PRECISION DEFAULT 1.0,
    ws_only BOOLEAN DEFAULT FALSE,
    active BOOLEAN DEFAULT TRUE,
    sort_order INTEGER DEFAULT 0
);

-- Default categories
INSERT INTO report_categories (title, description, default_weight, sort_order) VALUES
(1, 'Spam', 1.0, 1),
(2, 'Guro', 2.0, 2),
(3, 'CP (Illegal)', 10.0, 3),
(4, 'Doxxing', 5.0, 4),
(5, 'Suicide', 3.0, 5),
(6, 'Glowing Report', 0.5, 6);
```

### Migration 013: Mod Users Table
```sql
CREATE TABLE mod_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    level VARCHAR(20) NOT NULL,     -- janitor, mod, manager, admin
    allow TEXT,                      -- Comma-separated boards or 'all'
    deny TEXT,                       -- Denied actions
    flags TEXT,                      -- Comma-separated flags (developer, etc.)
    password_expired BOOLEAN DEFAULT FALSE,
    signed_agreement BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ,
    last_login TIMESTAMPTZ,
    created_by INTEGER REFERENCES mod_users(id)
);

CREATE INDEX idx_mod_users_username ON mod_users(username);
CREATE INDEX idx_mod_users_level ON mod_users(level);
```

### Migration 014: Janitor Applications Table
```sql
CREATE TABLE janitor_apps (
    id SERIAL PRIMARY KEY,
    unique_id VARCHAR(64) UNIQUE NOT NULL,
    firstname VARCHAR(255) NOT NULL,
    handle VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    age INTEGER NOT NULL,
    tz INTEGER NOT NULL,             -- Timezone offset
    hours INTEGER NOT NULL,          -- Hours per week
    times TEXT NOT NULL,             -- Available time frames
    http_ua TEXT,
    http_lang VARCHAR(64),
    ip_known BOOLEAN DEFAULT FALSE,
    board1 VARCHAR(20) NOT NULL,
    board2 VARCHAR(20),
    q1 TEXT NOT NULL,                -- Expertise
    q2 TEXT NOT NULL,                -- Problems
    q3 TEXT NOT NULL,                -- Favorite thing
    q4 TEXT NOT NULL,                -- Why you
    ip INET NOT NULL,
    closed INTEGER DEFAULT 0,        -- 0=pending, 9=ignored, 1=accepted, 2=rejected
    created_at TIMESTAMPTZ DEFAULT NOW(),
    reviewed_by INTEGER REFERENCES mod_users(id),
    reviewed_at TIMESTAMPTZ,
    review_notes TEXT
);

CREATE INDEX idx_janitor_apps_email ON janitor_apps(email);
CREATE INDEX idx_janitor_apps_status ON janitor_apps(closed);
CREATE INDEX idx_janitor_apps_board ON janitor_apps(board1);
```

### Migration 015: Event Log Table
```sql
CREATE TABLE event_log (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(64) NOT NULL,
    ip INET,
    board VARCHAR(20),
    thread_id BIGINT,
    post_id BIGINT,
    arg_num INTEGER,
    arg_str TEXT,
    pwd VARCHAR(64),
    req_sig TEXT,
    ua_sig TEXT,
    meta JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_event_log_type ON event_log(type);
CREATE INDEX idx_event_log_board ON event_log(board);
CREATE INDEX idx_event_log_created ON event_log(created_at DESC);
CREATE INDEX idx_event_log_ip ON event_log(ip);
```

### Migration 016: Janitor Stats Table
```sql
CREATE TABLE janitor_stats (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES mod_users(id),
    action_type INTEGER NOT NULL,    -- 0=denied, 1=accepted
    board VARCHAR(20),
    post_id BIGINT,
    requested_tpl INTEGER,
    accepted_tpl INTEGER,
    created_by_id INTEGER REFERENCES mod_users(id),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_janitor_stats_user ON janitor_stats(user_id);
CREATE INDEX idx_janitor_stats_created ON janitor_stats(created_at DESC);
```

### Migration 017: Report Clear Log Table
```sql
CREATE TABLE report_clear_log (
    id SERIAL PRIMARY KEY,
    ip INET NOT NULL,
    pwd VARCHAR(64),
    pass_id VARCHAR(64),
    category INTEGER,
    weight DOUBLE PRECISION,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_clear_log_ip ON report_clear_log(ip);
CREATE INDEX idx_clear_log_created ON report_clear_log(created_at DESC);
```

### Migration 018: Report Settings Table
```sql
CREATE TABLE report_settings (
    id SERIAL PRIMARY KEY,
    boards TEXT NOT NULL,            -- Comma-separated board list
    coef DOUBLE PRECISION DEFAULT 1.0,
    description TEXT
);

-- Default settings
INSERT INTO report_settings (boards, coef, description) VALUES
('b,rand', 0.5, 'Random boards - lower weight'),
('s,hc,hm', 2.0, 'Adult boards - higher weight'),
('pol', 1.5, 'Politically Incorrect - higher weight');
```

---

## Phase 2: Core Models & Services (Week 2-3)

### 2.1 Models (Hyperf/Eloquent)

#### Report Model
```php
<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Report extends Model
{
    protected ?string $table = 'reports';
    public $incrementing = true;
    public $timestamps = false;

    protected array $fillable = [
        'board', 'no', 'resto', 'report_category', 'weight',
        'ip', 'fourpass_id', 'pwd', 'req_sig', 'post_ip',
        'ws', 'cleared', 'cleared_by', 'post_json'
    ];

    protected array $casts = [
        'no' => 'integer',
        'resto' => 'integer',
        'weight' => 'float',
        'cleared' => 'boolean',
        'ws' => 'boolean',
        'post_json' => 'array',
    ];

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(ReportCategory::class, 'report_category');
    }
}
```

#### Ban Model
```php
<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;
use Carbon\Carbon;

class Ban extends Model
{
    protected ?string $table = 'banned_users';
    public $incrementing = true;
    public $timestamps = false;

    protected array $fillable = [
        'global', 'board', 'post_num', 'template_id', 'fourpass_id',
        'admin', 'reason', 'public_reason', 'length', 'active',
        'zonly', 'reverse', 'xff', 'post_json', 'admin_ip',
        'tripcode', 'host', 'rule', 'name', 'password'
    ];

    protected array $casts = [
        'global' => 'boolean',
        'active' => 'boolean',
        'zonly' => 'boolean',
        'post_num' => 'integer',
        'template_id' => 'integer',
        'post_json' => 'array',
        'now' => 'datetime',
        'length' => 'datetime',
        'unbannedon' => 'datetime',
        'post_time' => 'datetime',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeGlobal($query)
    {
        return $query->where('global', true);
    }

    public function scopeForBoard($query, string $board)
    {
        return $query->where('board', $board);
    }

    public function scopeForIP($query, string $ip)
    {
        return $query->where('host', $ip);
    }

    public function scopeForPass($query, string $fourpass_id)
    {
        return $query->where('fourpass_id', $fourpass_id);
    }

    public function isExpired(): bool
    {
        if (!$this->length) return false;
        return Carbon::now()->gt($this->length);
    }

    public function isActive(): bool
    {
        return $this->active && !$this->isExpired();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BanTemplate::class, 'template_id');
    }
}
```

#### BanTemplate Model
```php
<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class BanTemplate extends Model
{
    protected ?string $table = 'ban_templates';
    public $incrementing = true;
    public $timestamps = true;

    protected array $fillable = [
        'name', 'rule', 'bantype', 'publicreason', 'privatereason',
        'days', 'banlen', 'level', 'can_warn', 'postban', 'postban_arg',
        'action_type', 'save_type', 'active', 'created_by'
    ];

    protected array $casts = [
        'days' => 'integer',
        'banlen' => 'integer',
        'can_warn' => 'boolean',
        'active' => 'boolean',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForLevel($query, string $level)
    {
        $levelOrder = ['janitor' => 1, 'mod' => 2, 'manager' => 3, 'admin' => 4];
        $maxLevel = $levelOrder[$level] ?? 1;
        return $query->whereIn('level', array_keys(array_filter($levelOrder, fn($v) => $v <= $maxLevel)));
    }

    public function scopeForBoard($query, string $board)
    {
        return $query->where(function($q) use ($board) {
            $q->where('rule', 'like', "{$board}%")
              ->orWhere('rule', 'like', 'global%');
        });
    }

    public function isWarning(): bool
    {
        return $this->days === 0;
    }

    public function isPermanent(): bool
    {
        return $this->days === -1;
    }

    public function isGlobal(): bool
    {
        return $this->bantype === 'global';
    }
}
```

#### ModUser Model
```php
<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class ModUser extends Model
{
    protected ?string $table = 'mod_users';
    public $incrementing = true;
    public $timestamps = true;

    protected array $hidden = ['password_hash'];

    protected array $fillable = [
        'username', 'password_hash', 'level', 'allow', 'deny',
        'flags', 'password_expired', 'signed_agreement', 'created_by'
    ];

    protected array $casts = [
        'password_expired' => 'boolean',
        'signed_agreement' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'last_login' => 'datetime',
    ];

    public function getBoardsAttribute(): array
    {
        if ($this->allow === 'all') {
            return []; // All boards
        }
        return explode(',', $this->allow ?? '');
    }

    public function hasBoardAccess(string $board): bool
    {
        if ($this->allow === 'all') return true;
        return in_array($board, $this->boards);
    }

    public function hasLevel(string $level): bool
    {
        $levelOrder = ['janitor' => 1, 'mod' => 2, 'manager' => 3, 'admin' => 4];
        return ($levelOrder[$this->level] ?? 0) >= ($levelOrder[$level] ?? 0);
    }

    public function hasFlag(string $flag): bool
    {
        $flags = explode(',', $this->flags ?? '');
        return in_array($flag, $flags);
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }
}
```

### 2.2 Services

#### ReportService
Key methods to implement:
- `submitReport(array $data): bool` - Submit new report
- `getReports(string $board, int $page, array $filters): array` - Fetch reports with pagination
- `clearReport(int $id, string $modUsername): bool` - Clear a report
- `clearReporter(string $ip, string $pwd = null, string $passId = null): int` - Clear all reports by identifier
- `getReporters(int $postNo, string $board): array` - Get users who reported a post
- `banReporters(array $ids, int $templateId, bool $isWarn): array` - Ban users who reported
- `countReports(): array` - Get report counts by board
- `isReportForOwnPost(string $board, int $no): bool` - Check if reporting own post
- `enforceClearedAbuse(string $ip, string $pwd = null, string $passId = null): bool` - Auto-ban for abuse

#### BanService
Key methods to implement:
- `createBan(array $data): Ban` - Create new ban
- `createBanFromTemplate(int $templateId, array $context): Ban` - Use template
- `removeBan(int $banId, string $modUsername): bool` - Unban
- `getBansForIP(string $ip, int $limit = 50): array` - Ban history
- `getBansForPass(string $fourpass_id, int $limit = 50): array` - Pass ban history
- `getActiveBansSummary(string $ip, string $passId = null): array` - Summary stats
- `shouldUpgradeToGlobal(string $ip): bool` - Check local→global threshold
- `appendBanToBoard(string $board, string $ip): bool` - Add to board ban list

#### AccessService
Key methods to implement:
- `checkAccess(string $username, string $password): ?ModUser` - Authenticate
- `getAccessLevel(ModUser $user): array` - Get permissions array
- `canAccessBoard(ModUser $user, string $board): bool` - Board access check
- `canPerformAction(ModUser $user, string $action): bool` - Action permission check
- `signAgreement(int $userId): bool` - Sign volunteer agreement

#### JanitorAppService
Key methods to implement:
- `submitApplication(array $data): string` - Submit app, return unique_id
- `getApplication(string $uniqueId, string $email): ?JanitorApplication` - Fetch for editing
- `updateApplication(string $uniqueId, array $data): bool` - Update existing
- `getPendingApplications(array $boardFilter): array` - For review
- `reviewApplication(int $id, int $status, string $notes, ModUser $reviewer): bool` - Review
- `validateApplication(array $data): array` - Validation errors
- `isIPKnown(string $ip): bool` - Check posting history
- `isBotSpam(array $data): bool` - Detect bot submissions

---

## Phase 3: API Endpoints (Week 4)

### 3.1 Report Queue API (`/api/v1/mod/reports`)

| Method | Endpoint | Access | Description |
|--------|----------|--------|-------------|
| GET | `/reports` | Janitor+ | Get report queue (paginated) |
| GET | `/reports/count` | Janitor+ | Get report counts |
| POST | `/reports/clear` | Janitor+ | Clear report(s) |
| GET | `/reports/{id}/reporters` | Mod+ | Get reporters for post |
| POST | `/reports/ban-reporters` | Mod+ | Ban reporters |
| DELETE | `/reports/reporter` | Mod+ | Clear reporter (all reports) |
| GET | `/reports/orphaned` | Mod+ | Clear orphaned reports |
| GET | `/reports/templates` | Janitor+ | Get ban templates |

### 3.2 Ban Request API (`/api/v1/mod/ban-requests`)

| Method | Endpoint | Access | Description |
|--------|----------|--------|-------------|
| GET | `/ban-requests` | Mod+ | Get ban request queue |
| GET | `/ban-requests/count` | Mod+ | Get counts |
| POST | `/ban-requests/accept` | Mod+ | Approve request |
| POST | `/ban-requests/deny` | Mod+ | Deny request |
| POST | `/ban-requests/amend` | Mod+ | Amend & approve |

### 3.3 Ban Management API (`/api/v1/mod/bans`)

| Method | Endpoint | Access | Description |
|--------|----------|--------|-------------|
| GET | `/bans` | Mod+ | Search/list bans |
| POST | `/bans` | Mod+ | Create manual ban |
| DELETE | `/bans/{id}` | Mod+ | Remove ban |
| GET | `/bans/ip/{ip}` | Mod+ | Get ban history for IP |
| GET | `/bans/pass/{passId}` | Mod+ | Get ban history for Pass |
| POST | `/bans/delete-by-ip` | Mod+ | Delete all posts by IP |

### 3.4 Janitor Applications API (`/api/v1/janitor-apps`)

| Method | Endpoint | Access | Description |
|--------|----------|--------|-------------|
| GET | `/janitor-apps` | Public | Get application form |
| POST | `/janitor-apps` | Public | Submit application |
| GET | `/janitor-apps/{id}/edit` | Public | Edit existing (with email auth) |
| PUT | `/janitor-apps/{id}` | Public | Update application |
| GET | `/janitor-apps/pending` | Manager+ | Review queue |
| POST | `/janitor-apps/{id}/review` | Manager+ | Submit review decision |

### 3.5 Admin Panel API (`/api/v1/admin`)

| Method | Endpoint | Access | Description |
|--------|----------|--------|-------------|
| GET | `/admin/stats` | Manager+ | Dashboard statistics |
| GET | `/admin/staff-overview` | Manager+ | Staff activity stats |
| GET | `/admin/templates` | Manager+ | Manage ban templates |
| POST | `/admin/templates` | Manager+ | Create template |
| PUT | `/admin/templates/{id}` | Manager+ | Update template |
| GET | `/admin/report-categories` | Manager+ | Manage categories |
| POST | `/admin/report-categories` | Manager+ | Create category |
| PUT | `/admin/report-categories/{id}` | Manager+ | Update category |
| GET | `/admin/users` | Admin | Manage mod users |
| POST | `/admin/users` | Admin | Create mod user |
| PUT | `/admin/users/{id}` | Admin | Update user |

---

## Phase 4: Frontend UI (Week 5-6)

### 4.1 Report Queue Interface

**Layout:**
```
┌─────────────────────────────────────────────────────────────┐
│ [Filter: ____________] [⌕] [CLR] [IGN] [BR] [Admin]        │
│ Reports                                      [⚙ Settings]  │
├─────────────────────────────────────────────────────────────┤
│ [All] [WS] [a] [b] [c] [g] [pol] [v] [...]                 │
├─────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ /g/12345 [R:5] [W:15.5] [2h ago] [🚩]                  │ │
│ │ ┌─────────────────┐  Comment text here with greentext  │ │
│ │ │   [Image]       │  >>12340                            │ │
│ │ │   123x456       │                                     │ │
│ │ └─────────────────┘  [Clear] [Ban Reporters] [Details] │ │
│ └─────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ ... more reports ...                                    │ │
│ └─────────────────────────────────────────────────────────┘ │
├─────────────────────────────────────────────────────────────┤
│ Page: [1] [2] [3] ...                         [Refresh]    │ │
└─────────────────────────────────────────────────────────────┘
```

**Features:**
- Real-time report count updates (WebSocket/polling)
- Filter by content, thread ID, reporter IP
- Board filtering (single board, all, worksafe only)
- Sort by weight, time, board
- Highlight high-priority reports (weight > threshold)
- Expandable post details panel
- Quick clear with template selection
- Ban reporter dialog with template selection
- Dark theme toggle

### 4.2 Ban Request Interface

**Layout:**
```
┌─────────────────────────────────────────────────────────────┐
│ Ban Requests                                 [Refresh]     │
├─────────────────────────────────────────────────────────────┤
│ Priority: [3]  Pending: [45]                                │
├─────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ [!global1] /b/ - Posted by JanitorX  [2h ago]          │ │
│ │ ┌─────────────────┐  Reason: CP violation              │ │
│ │ │   [Image]       │  Host: 192.168.1.1 (US)           │ │
│ │ │                 │  Bans: 2 recent, 1 active         │ │
│ │ └─────────────────┘                                     │ │
│ │ [Accept] [Deny] [Amend] [View Full]                    │ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### 4.3 Janitor Application Form

**Public Form:**
```
┌─────────────────────────────────────────────────────────────┐
│ Janitor Application                                         │
├─────────────────────────────────────────────────────────────┤
│ First Name: [_______________________]                       │
│ Nickname: [_______________________]                         │
│ Email: [_______________________]                            │
│ Age: [___] Timezone: [GMT-5 ▼]                              │
│ Hours/week: [___] Available times: [___________________]    │
│                                                             │
│ First choice board: [/g/ ▼]                                 │
│ Second choice board: [/v/ ▼]                                │
│                                                             │
│ 1. Describe your expertise:                                 │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │                                                         │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ 2. Main problems facing the board:                          │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │                                                         │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ 3. Favorite thing about the board:                          │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │                                                         │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ 4. Why you're a good applicant:                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │                                                         │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ [CAPTCHA]                                                   │
│ [Submit Application]                                        │
└─────────────────────────────────────────────────────────────┘
```

### 4.4 Admin Dashboard

**Key Widgets:**
- Report queue stats (total, priority, by board)
- Ban request stats (pending, priority)
- Staff activity leaderboard (clears, bans, BR processed)
- Self-clear/delete monitoring
- Flood detection alerts
- Recent admin actions log

---

## Phase 5: Advanced Features (Week 7-8)

### 5.1 Report Weight System

**Board Coefficients:**
```php
// report_settings table
['boards' => 'b,rand', 'coef' => 0.5],   // Lower weight for random
['boards' => 's,hc,hm', 'coef' => 2.0],  // Higher for adult boards
['boards' => 'pol', 'coef' => 1.5],      // Higher for pol
```

**Weight Calculation:**
```php
$baseWeight = $category->default_weight ?? 1.0;
$boardCoef = $this->getBoardCoefficient($board);
$totalWeight = $baseWeight * $boardCoef;

// Thread reports get 25% boost
if ($resto === 0) {
    $totalWeight = ceil($totalWeight * 1.25);
}
```

**Global Unlock Threshold:**
- Reports with weight >= 1500 become visible to ALL janitors (not just board janitors)
- Highlight threshold for mods: 500

### 5.2 Report Abuse Detection

**Automatic Warnings/Bans:**
```php
const ABUSE_CLEAR_DAYS = 3;      // Window to check
const ABUSE_CLEAR_COUNT = 50;    // Clears before action
const ABUSE_CLEAR_BAN_INTERVAL = 5; // Days between auto-bans

// When user clears reports:
1. Log to report_clear_log
2. Count clears in window for IP/pass/pwd
3. If count > threshold:
   - Check for recent abuse bans
   - Issue warning (1 day) or ban (template days)
```

### 5.3 Ban Escalation

**Local → Global:**
```php
// After X local bans on different boards, upgrade to global
const LOCAL_TO_GLOBAL_THRES = 3;

public function shouldUpgradeToGlobal(string $ip): bool {
    $boards = Ban::where('host', $ip)
        ->where('global', false)
        ->where('active', true)
        ->groupBy('board')
        ->count();
    
    return $boards >= self::LOCAL_TO_GLOBAL_THRES;
}
```

### 5.4 Janitor Stats & Leaderboards

**Tracked Metrics:**
- Reports cleared (accepted/denied)
- Ban requests processed
- Manual bans issued
- Self-clears (monitoring)
- Fence-skipping (deleting outside boards)

**Monthly Scoreboard:**
```
Top Janitors (Last 30 Days)
┌──────────────┬─────────┬──────────┬────────────┐
│ User         │ Clears  │ BR Done  │ Bans       │
├──────────────┼─────────┼──────────┼────────────┤
│ JanitorA     │ 1,234   │ 89       │ 45         │
│ JanitorB     │ 987     │ 67       │ 32         │
│ JanitorC     │ 856     │ 54       │ 28         │
└──────────────┴─────────┴──────────┴────────────┘
```

---

## Phase 6: Security & Compliance (Week 9)

### 6.1 Access Control Matrix

| Action | Janitor | Mod | Manager | Admin |
|--------|---------|-----|---------|-------|
| View reports (assigned boards) | ✓ | ✓ | ✓ | ✓ |
| Clear reports | ✓ | ✓ | ✓ | ✓ |
| View all reports | ✗ | ✓ | ✓ | ✓ |
| Ban reporters | ✗ | ✓ | ✓ | ✓ |
| Clear reporter | ✗ | ✓ | ✓ | ✓ |
| Create ban requests | ✓ | ✓ | ✓ | ✓ |
| Approve ban requests | ✗ | ✓ | ✓ | ✓ |
| Manual bans | ✗ | ✓ | ✓ | ✓ |
| Delete by IP | ✗ | ✓ | ✓ | ✓ |
| Edit templates | ✗ | ✗ | ✓ | ✓ |
| Manage categories | ✗ | ✗ | ✓ | ✓ |
| Create mod users | ✗ | ✗ | ✗ | ✓ |
| Nuke (delete all) | ✗ | ✗ | ✗ | ✓ |

### 6.2 Audit Logging

All moderation actions logged to `event_log`:
- `report_clear` - Report cleared
- `reporter_ban` - Reporter banned
- `reporter_clear` - Reporter cleared
- `ban_create` - Ban created
- `ban_remove` - Ban removed
- `ban_request_accept` - BR approved
- `ban_request_deny` - BR denied
- `staff_self_clear` - Self-clear (own post)
- `staff_self_del` - Self-delete (own post)
- `fence_skip` - Delete outside boards

### 6.3 Volunteer Agreement

**Required before accessing tools:**
```
4chan Volunteer Moderator Agreement

1. I am at least 18 years of age.
2. I understand I am a volunteer with no special status.
3. I agree to keep my identity anonymous.
4. I will not abuse moderation tools for personal reasons.
5. I understand my access can be revoked at any time.
6. I agree to follow board rules and staff guidelines.

[ ] I have read and agree to this agreement.
[Sign Digitally]
```

### 6.4 Sensitive Data Handling

- IPs stored as INET (PostgreSQL)
- 4chan Pass IDs hashed before storage
- Passwords never logged
- Report JSON redacted for sensitive categories (CP)
- GeoIP lookup on-demand, not stored

---

## Phase 7: Testing & Deployment (Week 10)

### 7.1 Test Coverage

**Unit Tests:**
- Model relationships
- Service methods
- Weight calculations
- Access control checks

**Integration Tests:**
- Report submission flow
- Ban creation flow
- Janitor app review flow
- Template application

**E2E Tests:**
- Full mod panel workflow
- Multi-janitor scenarios
- Abuse detection

### 7.2 Performance Benchmarks

- Report queue load: <100ms for 1000 reports
- Ban lookup: <50ms for IP history
- Weight calculation: cached board coefs
- Real-time counts: <1s refresh

### 7.3 Deployment Checklist

- [ ] All migrations applied
- [ ] Default templates seeded
- [ ] Default categories seeded
- [ ] Admin user created
- [ ] Volunteer agreement text configured
- [ ] GeoIP database installed
- [ ] Cron jobs for pruning
- [ ] Monitoring dashboards configured
- [ ] Alert thresholds set

---

## Appendix A: Default Ban Templates

```sql
INSERT INTO ban_templates (name, rule, bantype, publicreason, privatereason, days, level) VALUES
-- Global templates
('Global 1 - Illegal', 'global1', 'global', 'Illegal content', 'CP/illegal', -1, 'mod'),
('Global 2 - Spam', 'global2', 'global', 'Spam', 'Repeat spam', 7, 'mod'),
('Global 3 - Troll', 'global3', 'global', 'Trolling', 'Severe trolling', 3, 'mod'),
('Global 5 - NWS', 'global5', 'global', 'NWS on worksafe', 'Guro/NSFW on WS', 3, 'janitor'),
('Global 9 - Evasion', 'global9', 'global', 'Ban evasion', 'Evasion', 14, 'mod'),

-- Board-specific templates
('b1 - Spam', 'b1', 'local', 'Spam', 'Spam post', 1, 'janitor'),
('b2 - Troll', 'b2', 'local', 'Trolling', 'Trolling', 3, 'janitor'),
('pol1 - Rule 1', 'pol1', 'local', 'Rule 1 violation', 'Inflammatory', 1, 'janitor'),
('g1 - Off-topic', 'g1', 'local', 'Off-topic', 'OT post', 0, 'janitor')
```

---

## Appendix B: Timeline Summary

| Phase | Duration | Deliverables |
|-------|----------|--------------|
| 1. Database | Week 1 | 11 migrations, schema ready |
| 2. Models/Services | Week 2-3 | 8 models, 4 services |
| 3. API | Week 4 | 30+ endpoints |
| 4. Frontend | Week 5-6 | Report queue, BR, applications |
| 5. Advanced | Week 7-8 | Weight system, abuse detection |
| 6. Security | Week 9 | Access control, audit logging |
| 7. Testing | Week 10 | Tests, deployment |

**Total Estimated Time: 10 weeks**

---

## Appendix C: Open Questions

1. **WebSocket vs Polling**: Should report queue use WebSocket for real-time updates or stick with polling?
2. **Pass Integration**: Do we integrate with existing 4chan Pass system or create new auth?
3. **NCMEC Reporting**: Should we automate NCMEC report generation for CP cases?
4. **Appeals System**: Include ban appeals in Phase 1 or defer?
5. **Multi-language**: Support for non-English janitors?

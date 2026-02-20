# Ashchan Admin Tools Implementation Guide

This guide provides step-by-step instructions for implementing the remaining admin tools in the Ashchan staff panel.

## Table of Contents

1. [Overview](#overview)
2. [Architecture Pattern](#architecture-pattern)
3. [Capcodes Management](#capcodes-management)
4. [IP Range Bans](#ip-range-bans)
5. [Autopurge Rules](#autopurge-rules)
6. [DMCA Tool](#dmca-tool)
7. [Blotter Messages](#blotter-messages)
8. [Site Messages](#site-messages)
9. [Testing Checklist](#testing-checklist)

---

## Overview

### Remaining Tools to Implement

| Tool | Route | Priority | Complexity |
|------|-------|----------|------------|
| Capcodes | `/staff/capcodes` | High | Medium |
| IP Range Bans | `/staff/iprangebans` | High | Medium |
| Autopurge | `/staff/autopurge` | Medium | High |
| DMCA Tool | `/staff/dmca` | Medium | High |
| Blotter | `/staff/blotter` | Low | Low |
| Site Messages | `/staff/site-messages` | Low | Low |

### Database Tables (Already Created)

All tables are created in `db/migrations/004_account_management.sql`:
- `capcodes`
- `ip_range_bans`
- `autopurge_rules`
- `dmca_notices`
- `dmca_takedowns`
- `blotter_messages`
- `site_messages`

---

## Architecture Pattern

All tools follow the same pattern as AccountManagementController:

### 1. Controller Structure

```php
<?php
declare(strict_types=1);

namespace App\Controller\Staff;

use App\Service\ViewService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/staff/{tool}')]
final class {Tool}Controller
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        // List all items
        $items = Db::table('{table}')->get();
        $html = $this->viewService->render('staff/{tool}/index', ['items' => $items]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        // Show create form
        $html = $this->viewService->render('staff/{tool}/create');
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        // Create new item
        $body = $this->request->getParsedBody();
        // Validation...
        Db::table('{table}')->insert([...]);
        return $this->response->json(['success' => true, 'redirect' => '/staff/{tool}']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        // Show edit form
        $item = Db::table('{table}')->where('id', $id)->first();
        $html = $this->viewService->render('staff/{tool}/edit', ['item' => $item]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        // Update item
        $body = $this->request->getParsedBody();
        Db::table('{table}')->where('id', $id)->update([...]);
        return $this->response->json(['success' => true, 'redirect' => '/staff/{tool}']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        // Delete item
        Db::table('{table}')->where('id', $id)->delete();
        return $this->response->json(['success' => true]);
    }
}
```

### 2. Routes Configuration

Add to `services/api-gateway/config/routes.php`:

```php
// {Tool} Management (Manager+)
Router::get('/staff/{tool}', [\App\Controller\Staff\{Tool}Controller::class, 'index']);
Router::get('/staff/{tool}/create', [\App\Controller\Staff\{Tool}Controller::class, 'create']);
Router::post('/staff/{tool}/store', [\App\Controller\Staff\{Tool}Controller::class, 'store']);
Router::get('/staff/{tool}/{id:\d+}/edit', [\App\Controller\Staff\{Tool}Controller::class, 'edit']);
Router::post('/staff/{tool}/{id:\d+}/update', [\App\Controller\Staff\{Tool}Controller::class, 'update']);
Router::post('/staff/{tool}/{id:\d+}/delete', [\App\Controller\Staff\{Tool}Controller::class, 'delete']);
```

### 3. View Templates

Create views in `services/api-gateway/views/staff/{tool}/`:
- `index.html` - List all items
- `create.html` - Create form
- `edit.html` - Edit form

Use existing account management views as templates.

### 4. Build and Deploy

```bash
cd /home/abrookstgz/ashchan/services/api-gateway
podman build -t ashchan/api-gateway:latest .
podman restart ashchan-api-gateway
```

---

## Capcodes Management

**File:** `services/api-gateway/app/Controller/Staff/CapcodeController.php`

### Features
- Create/edit/delete capcodes
- Assign capcodes to users
- Set board restrictions
- Color coding for different capcode types

### Implementation

```php
#[Controller(prefix: '/staff/capcodes')]
final class CapcodeController
{
    // Standard CRUD methods...

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        
        // Generate secure tripcode
        $tripcode = '!!' . bin2hex(random_bytes(16));
        
        Db::table('capcodes')->insert([
            'name' => $body['name'],
            'tripcode' => $tripcode,
            'label' => $body['label'] ?? '',
            'color' => $body['color'] ?? '#0000FF',
            'boards' => $body['boards'] ?? [],
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        return $this->response->json(['success' => true, 'redirect' => '/staff/capcodes']);
    }
}
```

### View Fields (create.html)
- Name (text)
- Label (text) - Display text like "Administrator"
- Color (color picker) - Hex color for capcode
- Boards (checkboxes) - Which boards this capcode works on
- Active (checkbox)

---

## IP Range Bans

**File:** `services/api-gateway/app/Controller/Staff/IpRangeBanController.php`

### Features
- Ban IP ranges (CIDR notation)
- Set expiration dates
- Board-specific range bans
- Reason tracking

### Implementation

```php
#[Controller(prefix: '/staff/iprangebans')]
final class IpRangeBanController
{
    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        
        // Parse CIDR notation (e.g., "192.168.1.0/24")
        [$rangeStart, $rangeEnd] = $this->parseCidr($body['range']);
        
        Db::table('ip_range_bans')->insert([
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'reason' => $body['reason'],
            'boards' => $body['boards'] ?? [],
            'expires_at' => $body['expires_at'] ?: null,
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        return $this->response->json(['success' => true, 'redirect' => '/staff/iprangebans']);
    }
    
    private function parseCidr(string $cidr): array
    {
        // Implement CIDR parsing
        // Returns [range_start, range_end] as INET
        [$ip, $mask] = explode('/', $cidr);
        // ... calculation logic
    }
}
```

### View Fields
- Range (text, CIDR format like "192.168.1.0/24")
- Reason (textarea)
- Boards (checkboxes)
- Expires At (datetime picker, optional)
- Active (checkbox)

### Helper Function

Add CIDR parsing helper:

```php
private function cidrToRange(string $cidr): array
{
    list($ip, $mask) = explode('/', $cidr);
    $ipLong = ip2long($ip);
    $maskLong = ~((1 << (32 - (int)$mask)) - 1);
    $start = long2ip($ipLong & $maskLong);
    $end = long2ip($ipLong | ~$maskLong);
    return [$start, $end];
}
```

---

## Autopurge Rules

**File:** `services/api-gateway/app/Controller/Staff/AutopurgeController.php`

### Features
- Create regex or literal pattern matches
- Auto-delete posts matching patterns
- Optional ban on match
- Track hit counts

### Implementation

```php
#[Controller(prefix: '/staff/autopurge')]
final class AutopurgeController
{
    #[PostMapping(path: 'test')]
    public function test(): ResponseInterface
    {
        // Test pattern against sample text
        $body = $this->request->getParsedBody();
        $pattern = $body['pattern'];
        $sample = $body['sample_text'];
        
        $matched = false;
        if ($body['is_regex']) {
            $matched = @preg_match('/' . $pattern . '/i', $sample);
        } else {
            $matched = stripos($sample, $pattern) !== false;
        }
        
        return $this->response->json([
            'matched' => (bool)$matched,
            'pattern' => $pattern,
        ]);
    }
}
```

### View Fields
- Pattern (text)
- Is Regex (checkbox)
- Boards (checkboxes)
- Purge Threads (checkbox)
- Purge Replies (checkbox)
- Ban Length Days (number)
- Ban Reason (text)
- Active (checkbox)

### Integration Point

To actually use autopurge, integrate with the post creation flow in the boards service:

```php
// In boards-threads-posts service, when creating a post:
$rules = Db::table('autopurge_rules')
    ->where('is_active', true)
    ->where('boards', '=', []) // All boards
    ->orWhere('boards', 'contains', $board)
    ->get();

foreach ($rules as $rule) {
    $matched = false;
    if ($rule->is_regex) {
        $matched = @preg_match('/' . $rule->pattern . '/i', $comment);
    } else {
        $matched = stripos($comment, $rule->pattern) !== false;
    }
    
    if ($matched) {
        // Delete post
        // Log hit
        // Apply ban if configured
    }
}
```

---

## DMCA Tool

**File:** `services/api-gateway/app/Controller/Staff/DmcaController.php`

### Features
- Log DMCA notices
- Track takedown actions
- Manage claimant information
- Status tracking (pending/processed/rejected)

### Implementation

```php
#[Controller(prefix: '/staff/dmca')]
final class DmcaController
{
    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $notices = Db::table('dmca_notices')
            ->orderBy('received_at', 'desc')
            ->get();
        $html = $this->viewService->render('staff/dmca/index', ['notices' => $notices]);
        return $this->response->html($html);
    }

    #[GetMapping(path: '{id:\d+}')]
    public function view(int $id): ResponseInterface
    {
        $notice = Db::table('dmca_notices')->where('id', $id)->first();
        $takedowns = Db::table('dmca_takedowns')->where('notice_id', $id)->get();
        $html = $this->viewService->render('staff/dmca/view', [
            'notice' => $notice,
            'takedowns' => $takedowns,
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/process')]
    public function process(int $id): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        
        // Update notice status
        Db::table('dmca_notices')->where('id', $id)->update([
            'status' => 'processed',
            'processed_at' => date('Y-m-d H:i:s'),
            'processed_by' => \Hyperf\Context\Context::get('staff_user')['id'],
            'notes' => $body['notes'] ?? '',
        ]);
        
        // Log takedowns
        foreach ($body['takedowns'] ?? [] as $takedown) {
            Db::table('dmca_takedowns')->insert([
                'notice_id' => $id,
                'board' => $takedown['board'],
                'post_no' => $takedown['post_no'],
                'takedown_reason' => $takedown['reason'],
                'takedown_at' => date('Y-m-d H:i:s'),
                'takedown_by' => \Hyperf\Context\Context::get('staff_user')['id'],
            ]);
        }
        
        return $this->response->json(['success' => true]);
    }
}
```

### View Fields (Notice Form)
- Claimant Name (text)
- Claimant Company (text)
- Claimant Email (email)
- Claimant Phone (text)
- Copyrighted Work (textarea)
- Infringing URLs (textarea, one per line)
- Statement (textarea)
- Signature (text)
- Status (select: pending/processed/rejected)

---

## Blotter Messages

**File:** `services/api-gateway/app/Controller/Staff/BlotterController.php`

### Features
- Post site-wide announcements
- Priority ordering
- HTML support option
- Active/inactive toggle

### Implementation

```php
#[Controller(prefix: '/staff/blotter')]
final class BlotterController
{
    // Standard CRUD...
    
    #[GetMapping(path: 'preview')]
    public function preview(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $message = $body['message'];
        
        if ($body['is_html']) {
            // Sanitize HTML
            $message = strip_tags($message, '<p><br><strong><em><a>');
        } else {
            $message = nl2br(htmlspecialchars($message));
        }
        
        return $this->response->json(['preview' => $message]);
    }
}
```

### View Fields
- Message (textarea)
- Is HTML (checkbox)
- Priority (number, higher = more important)
- Active (checkbox)

### Display on Frontend

To show blotter messages on the frontend:

```php
// In frontend controller
$blotter = Db::table('blotter_messages')
    ->where('is_active', true)
    ->orderBy('priority', 'desc')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();
```

---

## Site Messages

**File:** `services/api-gateway/app/Controller/Staff/SiteMessageController.php`

### Features
- Targeted messages by board
- Scheduled start/end times
- HTML support
- Global or board-specific

### Implementation

```php
#[Controller(prefix: '/staff/site-messages')]
final class SiteMessageController
{
    // Standard CRUD...
    
    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        
        Db::table('site_messages')->insert([
            'title' => $body['title'],
            'message' => $body['message'],
            'is_html' => isset($body['is_html']),
            'boards' => $body['boards'] ?? [], // Empty = all boards
            'start_at' => $body['start_at'] ?: date('Y-m-d H:i:s'),
            'end_at' => $body['end_at'] ?: null,
            'is_active' => isset($body['is_active']),
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => \Hyperf\Context\Context::get('staff_user')['id'],
        ]);
        
        return $this->response->json(['success' => true, 'redirect' => '/staff/site-messages']);
    }
}
```

### View Fields
- Title (text)
- Message (textarea)
- Is HTML (checkbox)
- Boards (checkboxes, empty = all)
- Start At (datetime)
- End At (datetime, optional)
- Active (checkbox)

---

## Testing Checklist

### For Each Tool

- [ ] Can list all items
- [ ] Can create new item
- [ ] Can edit existing item
- [ ] Can delete item
- [ ] Validation works correctly
- [ ] Error messages display properly
- [ ] Success redirects work
- [ ] Manager+ access required (test with janitor account)
- [ ] Audit log entries created

### Integration Tests

**Capcodes:**
- [ ] Capcode appears on posts when used
- [ ] Board restrictions enforced
- [ ] Color displays correctly

**IP Range Bans:**
- [ ] CIDR parsing works correctly
- [ ] Range bans checked during post
- [ ] Expired bans auto-disabled

**Autopurge:**
- [ ] Pattern matching works (regex and literal)
- [ ] Posts auto-deleted on match
- [ ] Bans applied if configured
- [ ] Hit counter increments

**DMCA:**
- [ ] Notice status updates correctly
- [ ] Takedowns logged properly
- [ ] Can view notice history

**Blotter:**
- [ ] Messages display on frontend
- [ ] Priority ordering works
- [ ] HTML sanitization works

**Site Messages:**
- [ ] Board targeting works
- [ ] Scheduled messages activate/deactivate
- [ ] Expired messages hidden

---

## Quick Start Commands

```bash
# After creating each controller:
cd /home/abrookstgz/ashchan/services/api-gateway
podman build -t ashchan/api-gateway:latest .
podman restart ashchan-api-gateway

# Test the tool
curl -s -b /tmp/cookies.txt http://localhost:9501/staff/{tool}

# Check logs for errors
podman logs ashchan-api-gateway 2>&1 | tail -50
```

---

## Common Issues & Solutions

### Issue: "Template not found"
**Solution:** Copy view templates to container:
```bash
podman cp views/staff/{tool}/ ashchan-api-gateway:/app/views/staff/{tool}/
```

### Issue: "Class not found" for controller
**Solution:** Rebuild container to pick up new files:
```bash
podman build --no-cache -t ashchan/api-gateway:latest .
```

### Issue: Database table doesn't exist
**Solution:** Run migration:
```bash
podman cp db/migrations/004_account_management.sql ashchan-postgres:/tmp/
podman exec ashchan-postgres psql -U ashchan -d ashchan -f /tmp/004_account_management.sql
```

### Issue: CSRF token errors
**Solution:** Add CSRF token generation to controller:
```php
private function generateCsrfToken(): string
{
    $user = \Hyperf\Context\Context::get('staff_user');
    $token = bin2hex(random_bytes(32));
    Db::table('csrf_tokens')->insert([
        'user_id' => $user['id'],
        'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + 86400),
    ]);
    return $token;
}
```

---

## File Locations Reference

```
ashchan/
├── db/migrations/
│   └── 004_account_management.sql    # Database schema
├── services/api-gateway/
│   ├── app/Controller/Staff/
│   │   ├── AccountManagementController.php  # Example
│   │   ├── CapcodeController.php            # TODO
│   │   ├── IpRangeBanController.php         # TODO
│   │   ├── AutopurgeController.php          # TODO
│   │   ├── DmcaController.php               # TODO
│   │   ├── BlotterController.php            # TODO
│   │   └── SiteMessageController.php        # TODO
│   ├── config/
│   │   └── routes.php                       # Add routes here
│   └── views/staff/
│       ├── accounts/                        # Example views
│       ├── capcodes/                        # TODO
│       ├── iprangebans/                     # TODO
│       ├── autopurge/                       # TODO
│       ├── dmca/                            # TODO
│       ├── blotter/                         # TODO
│       └── site-messages/                   # TODO
```

---

## Priority Order

1. **Capcodes** - Most requested feature, relatively simple
2. **IP Range Bans** - Important for spam control
3. **Blotter** - Simple, useful for announcements
4. **Site Messages** - Simple, useful for targeted announcements
5. **Autopurge** - Complex, requires post-flow integration
6. **DMCA Tool** - Complex, mostly administrative

---

## Notes for Next Agent

- All database tables are already created in migration 004
- Use AccountManagementController as the reference implementation
- Test each tool thoroughly before moving to the next
- Remember to add audit logging for all actions
- Consider adding bulk actions where appropriate (bulk delete, bulk activate)
- Add search/filter functionality for tools with many items
- Consider adding export functionality (CSV) for DMCA and logs

Good luck! 🚀

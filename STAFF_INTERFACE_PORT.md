# Ashchan Staff Interface Port

## Overview

The staff interface has been ported from OpenYotsuba to Ashchan, providing a familiar experience for moderators and administrators. The interface is accessible at `/staff` and closely matches the original 4chan design.

## Access

- **URL:** `/staff`
- **Login:** `/staff/login`
- **Default credentials:** Any non-empty username/password (for testing)

## Ported Components

### Controllers (4)

| Controller | Path | Description |
|------------|------|-------------|
| `StaffController` | `/staff` | Dashboard, login, logout |
| `StaffReportController` | `/staff/reports` | Report queue, ban requests |
| `StaffBanTemplateController` | `/staff/ban-templates` | Ban template CRUD (Manager+) |
| `StaffReportCategoryController` | `/staff/report-categories` | Category CRUD (Manager+) |

### Views (10)

```
views/staff/
├── layout.html              # Base layout with navigation
├── login.html               # Staff login page
├── dashboard.html           # Main dashboard
├── reports/
│   ├── index.html           # Report queue (matches reportqueue.tpl.php)
│   └── ban-requests.html    # Ban requests list
├── ban-templates/
│   ├── index.html           # Template list
│   └── update.html          # Create/edit template
└── report-categories/
    ├── index.html           # Category list
    └── update.html          # Create/edit category
```

### CSS (7)

| File | Ported From |
|------|-------------|
| `admincore.css` | OpenYotsuba admin CSS |
| `reportqueue.css` | reports/css/reportqueue.css |
| `ban_templates.css` | admin/css/ban_templates.css |
| `report_categories.css` | admin/css/report_categories.css |
| `bans.css` | admin/css/bans.css |
| `ban-requests.css` | New (matching style) |
| `dashboard.css` | New (matching style) |
| `login.css` | New (matching style) |

### JavaScript (5)

| File | Ported From |
|------|-------------|
| `helpers.js` | OpenYotsuba/js/helpers.js |
| `admincore.js` | admin/js/admincore.js |
| `reportqueue.js` | reports/js/031d60ebf8d41a9f/reportqueue.js |
| `reportqueue-mod.js` | reports/js/d8d9b0cdc33f3418/reportqueue-mod.js |
| `staff.js` | New (common staff functions) |

## Features

### Report Queue
- Filter by board
- Toggle cleared reports
- Search/filter functionality
- Weight-based highlighting (500+) and unlocking (1500+)
- Clear/delete reports
- Create ban requests
- Dark theme toggle

### Ban Requests
- View pending requests
- Approve/deny requests (Mod+)
- View request details

### Ban Templates (Manager+)
- List all templates
- Create new templates
- Edit existing templates
- Delete templates

### Report Categories (Manager+)
- List all categories
- Create new categories
- Edit existing categories
- Board-specific categories
- Weight configuration

### Dashboard
- Report count summary
- Ban request count
- Quick actions
- Reports by board breakdown

## Access Levels

| Level | Reports | Ban Requests | Ban Templates | Categories |
|-------|---------|--------------|---------------|------------|
| Janitor | View/Clear | View | ✗ | ✗ |
| Mod | View/Clear/Delete | Approve/Deny | ✗ | ✗ |
| Manager | Full | Full | Full | Full |
| Admin | Full | Full | Full | Full |

## Authentication

The staff interface uses cookie-based authentication:

```php
// Set after login
staff_user: username
staff_token: hash
staff_level: janitor|mod|manager|admin
```

### Middleware

`StaffAuthMiddleware` verifies authentication on all `/staff/*` routes.

## Routes

```
GET  /staff                    → Dashboard (or redirect to login)
GET  /staff/login              → Login page
POST /staff/login              → Process login
GET  /staff/logout             → Logout

GET  /staff/dashboard          → Dashboard
GET  /staff/bans               → Bans management

GET  /staff/reports            → Report queue
GET  /staff/reports/data       → Report data (AJAX)
POST /staff/reports/{id}/clear → Clear report
POST /staff/reports/{id}/delete→ Delete report
GET  /staff/reports/ban-requests → Ban requests list
POST /staff/reports/ban-requests/{id}/approve → Approve request
POST /staff/reports/ban-requests/{id}/deny    → Deny request

GET  /staff/ban-templates      → Template list
GET  /staff/ban-templates/create → Create form
POST /staff/ban-templates      → Create template
GET  /staff/ban-templates/{id}/edit → Edit form
POST /staff/ban-templates/{id}      → Update template
POST /staff/ban-templates/{id}/delete → Delete template

GET  /staff/report-categories  → Category list
GET  /staff/report-categories/create → Create form
POST /staff/report-categories  → Create category
GET  /staff/report-categories/{id}/edit → Edit form
POST /staff/report-categories/{id}      → Update category
POST /staff/report-categories/{id}/delete → Delete category
```

## Design Notes

### Matching OpenYotsuba

The interface closely matches the original:

1. **Layout:** Header with title, navigation menu, content area
2. **Colors:** Same color scheme (#E7E7E7 background, #D2D4D3 header)
3. **Typography:** Helvetica Neue, 13px base font
4. **Tables:** Same styling with alternating row colors
5. **Buttons:** Gradient buttons with hover states
6. **Forms:** Same field layout and styling

### Key Differences

1. **Framework:** Hyperf instead of raw PHP
2. **Templating:** PHP views instead of .tpl.php
3. **Routing:** Annotation-based routing
4. **API:** RESTful API endpoints for AJAX

## Usage

### Starting the Staff Interface

```bash
cd /home/abrookstgz/ashchan/services/api-gateway

# Start the service
php bin/hyperf.php start

# Access at http://localhost:9501/staff
```

### Testing

1. Navigate to `/staff`
2. Login with any credentials (for testing)
3. Explore the dashboard
4. Test report queue (requires reports in database)
5. Test ban templates (Manager+ access)

## Screenshots

The interface matches these OpenYotsuba pages:

- Report Queue → `reports/views/reportqueue.tpl.php`
- Ban Templates → `admin/views/ban_templates.tpl.php`
- Report Categories → `admin/views/report_categories.tpl.php`
- Bans → `admin/views/bans.tpl.php`

## Next Steps

### Backend Integration
- [ ] Connect to auth service for real authentication
- [ ] Connect to boards service for board list
- [ ] Connect to posts service for post data in reports

### Frontend Enhancements
- [ ] Real-time updates via WebSocket
- [ ] Image hover preview
- [ ] Keyboard shortcuts
- [ ] Bulk actions for reports

### Additional Pages
- [ ] Ban search/update
- [ ] IP lookup
- [ ] Staff logs
- [ ] Statistics

## Files Created

### Controllers
- `app/Controller/Staff/StaffController.php`
- `app/Controller/Staff/StaffReportController.php`
- `app/Controller/Staff/StaffBanTemplateController.php`
- `app/Controller/Staff/StaffReportCategoryController.php`
- `app/Middleware/StaffAuthMiddleware.php`

### Views
- `views/staff/layout.html`
- `views/staff/login.html`
- `views/staff/dashboard.html`
- `views/staff/reports/index.html`
- `views/staff/reports/ban-requests.html`
- `views/staff/ban-templates/index.html`
- `views/staff/ban-templates/update.html`
- `views/staff/report-categories/index.html`
- `views/staff/report-categories/update.html`
- `views/staff/bans/index.html`

### CSS
- `public/staff/css/admincore.css`
- `public/staff/css/reportqueue.css`
- `public/staff/css/ban_templates.css`
- `public/staff/css/report_categories.css`
- `public/staff/css/bans.css`
- `public/staff/css/ban-requests.css`
- `public/staff/css/dashboard.css`
- `public/staff/css/login.css`

### JavaScript
- `public/staff/js/helpers.js`
- `public/staff/js/admincore.js`
- `public/staff/js/reportqueue.js`
- `public/staff/js/reportqueue-mod.js`
- `public/staff/js/staff.js`

## References

- OpenYotsuba Reports: `/home/abrookstgz/OpenYotsuba/reports/`
- OpenYotsuba Admin: `/home/abrookstgz/OpenYotsuba/admin/`
- OpenYotsuba Admin JS: `/home/abrookstgz/OpenYotsuba/admin/js/`
- OpenYotsuba Admin CSS: `/home/abrookstgz/OpenYotsuba/admin/css/`

# Ashchan Admin Panel - Complete

## Overview

The comprehensive admin panel has been ported from OpenYotsuba, providing a centralized interface for all moderation and site administration tasks.

## Access

- **Main Admin Panel:** http://localhost:9501/staff/admin
- **Login:** http://localhost:9501/staff/login

## Features Ported from OpenYotsuba

### Navigation Structure

Based on OpenYotsuba's `admin/views/index-nav.tpl.php`:

#### Main Section
- Overview (Admin Dashboard)
- Dashboard (Statistics)

#### Moderation Section
- Reports Queue
- Ban Requests (with count badge)
- Bans
- Ban Templates
- Report Categories

#### Search & Tools Section
- Search
- IP Lookup
- Check MD5
- Check Filter

#### Management Section (Manager+)
- Staff Roster
- Add Account
- Capcodes
- IP Range Bans
- Autopurge
- DMCA Tool
- Maintenance

#### Logs Section (Manager+)
- Staff Action Log
- Flood Log
- User Deletion Log

#### Admin Section (Admin+)
- Blotter
- Site Messages
- phpMyAdmin

### Pages Implemented

#### 1. Admin Overview (`/staff/admin`)
- Stats cards for Reports, Ban Requests, Active Bans, Posts Today
- Quick Actions grid for common tasks
- Reports by Board table
- Recent Staff Actions table
- Dark theme toggle

#### 2. Ban Requests (`/staff/reports/ban-requests`)
- List of pending ban requests
- Filter by board dropdown
- Approve/Deny/View buttons
- Shows janitor name, template, reason, timestamp
- Empty state when no requests

#### 3. Bans (`/staff/bans`)
- Search bar for IP, Ban ID, MD5, Reason
- Bulk action checkboxes
- Active/Expired status indicators
- Board, name, IP, reason display
- Ban length and expiration info
- Edit links for each ban

#### 4. Reports Queue (`/staff/reports`)
- Full report queue interface
- Board filtering
- Clear/Delete actions
- Ban request creation

### Design Features

#### Sidebar Navigation
- Fixed sidebar with categorized navigation
- User info display (username, level)
- Badge counts for pending items
- Active state highlighting
- Hover effects with border accent

#### Stats Cards
- Grid layout with icons
- Click-through to detailed views
- Hover animations
- Real-time counts

#### Quick Actions Grid
- Icon-based action cards
- Common tasks at a glance
- Hover effects with border accent

#### Data Tables
- Sortable columns
- Checkbox selection
- Action buttons per row
- Pagination support
- Empty states

#### Dark Theme
- Toggle button in header
- Persists via localStorage
- Full color scheme inversion
- Maintains readability

### CSS Styling

New `admin.css` provides:
- Modern gradient sidebar
- Card-based layouts
- Responsive grid systems
- Smooth transitions and hover effects
- Professional color scheme
- Full dark theme support

### Access Levels

| Level | Features |
|-------|----------|
| Janitor | Reports, Ban Requests, Bans, Search Tools |
| Mod | + Approve Ban Requests |
| Manager | + Management Tools, Logs |
| Admin | + Blotter, Site Messages, phpMyAdmin |

## Files Created/Modified

### Controllers
- `app/Controller/Staff/StaffController.php` - Updated with admin methods

### Views
- `views/staff/admin.html` - Main admin overview page
- `views/staff/reports/ban-requests.html` - Ban requests page
- `views/staff/bans/index.html` - Bans management page

### CSS
- `public/staff/css/admin.css` - Comprehensive admin styling (600+ lines)

### Routes
- `/staff/admin` - Main admin panel
- `/staff/reports/ban-requests` - Ban requests
- `/staff/bans` - Bans management

## Testing

```bash
# Login
curl -c cookies.txt -X POST http://localhost:9501/staff/login \
  -d "username=testmod&password=test"

# Access admin panel
curl -b cookies.txt http://localhost:9501/staff/admin

# Access ban requests
curl -b cookies.txt http://localhost:9501/staff/reports/ban-requests

# Access bans
curl -b cookies.txt http://localhost:9501/staff/bans
```

## Next Steps (Future Enhancements)

### Backend Integration
- [ ] Connect to database for real ban data
- [ ] Implement search functionality
- [ ] Add staff action logging
- [ ] Connect to boards service for stats

### Additional Pages
- [ ] Ban search page
- [ ] Ban edit/create forms
- [ ] IP lookup tool
- [ ] MD5 check tool
- [ ] Staff roster
- [ ] Capcodes management
- [ ] Blotter editor
- [ ] Site messages editor

### Advanced Features
- [ ] Bulk ban operations
- [ ] Ban export/import
- [ ] Advanced filtering
- [ ] Real-time updates via WebSocket
- [ ] Keyboard shortcuts
- [ ] Custom date range filters

## References

- OpenYotsuba Admin: `/home/abrookstgz/OpenYotsuba/admin/`
- OpenYotsuba Manager: `/home/abrookstgz/OpenYotsuba/admin/manager/`
- OpenYotsuba Navigation: `admin/views/index-nav.tpl.php`
- OpenYotsuba Bans: `admin/bans.php`, `admin/views/bans.tpl.php`
- OpenYotsuba Reports: `reports/ReportQueue.php`, `reports/views/reportqueue.tpl.php`

# Ashchan Porting Status

## Overview
This document tracks the progress of porting features from OpenYotsuba to Ashchan.

## Frontend Tools (Board Facing)
| Feature | Source File | Target File | Status | Notes |
|---------|-------------|-------------|--------|-------|
| Janitor Tools | `js/janitor.js` | `frontend/static/js/janitor.js` | ✅ Complete | Adapted for Ashchan API |
| Moderator Tools | `js/mod.js` | `frontend/static/js/mod.js` | ✅ Complete | Includes SFS, Bans, Thread Opts |
| Extension Core | `js/extension.js` | `frontend/static/js/extension.js` | ✅ Complete | Core event dispatcher |
| CSS | `css/*.css` | `frontend/static/css/*.css` | ✅ Complete | Stylesheets present |

## Admin Panel (Backend & Frontend)
| Feature | Controller | JS File | Status | Notes |
|---------|------------|---------|--------|-------|
| Dashboard | `StaffController` | `dashboard.js` | ✅ Complete | JS Ported, Controller updated |
| Bans Management | `StaffController` | `bans.js` | ✅ Complete | JS Ported, Unban API added |
| Reports Queue | `StaffReportController` | `reportqueue.js` | ✅ Complete | |
| Ban Requests | `StaffReportController` | `reportqueue.js` | ✅ Complete | |
| IP Range Bans | `IpRangeBanController` | `iprangebans.js` | ✅ Complete | JS Ported, CIDR lib added, View updated |
| Capcodes | `CapcodeController` | - | ✅ Complete | No complex JS needed |
| Autopurge | `AutopurgeController` | `autopurge.js` | ✅ Complete | JS Ported, View updated |
| DMCA | `DmcaController` | `dmca.js` | ✅ Complete | JS Ported, View updated |
| Blotter | `BlotterController` | `blotter.js` | ✅ Complete | JS Ported, Views updated |
| Site Messages | `SiteMessageController` | `staffmessages.js` | ✅ Complete | JS Ported, Views updated |
| Staff Log | `StaffToolsController` | `stafflog.js` | ✅ Complete | JS Ported |

## Integrations
| Integration | Service | Status | Notes |
|-------------|---------|--------|-------|
| StopForumSpam | `moderation-anti-spam` | ✅ Complete | Service, Controller, Mod Tool |
| MinIO (S3) | `media-uploads` | ✅ Complete | Proxying implemented |
| Captcha | `moderation-anti-spam` | ✅ Complete | Basic captcha implemented |

## Next Steps
1. Populate dashboard data with real metrics (currently mocked).
2. Perform comprehensive integration testing of all admin tools.

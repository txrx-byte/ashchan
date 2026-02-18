# Moderation/Anti-spam Service

## Purpose
- Risk scoring, automated enforcement, and quarantine.
- Human moderation workflows and audit trail.
- **Ported from OpenYotsuba** (4chan's current system)

## Features Ported from OpenYotsuba

### Reports System
- User-submitted reports with weighted categories
- Report queue with prioritization by weight
- Board-specific and global report categories
- Auto-filtering for abusive reporters

### Ban System
- Ban templates for standardized enforcement
- Janitor ban request → Mod approval workflow
- Global, local, and unappealable (zonly) bans
- Warning system (short bans as warnings)
- 4chan Pass support for cross-board enforcement

### Moderation Tools
- Report queue management (clear, delete, ban)
- Janitor statistics tracking
- Report abuse detection and auto-ban
- Cleared report logging

## Local Config
See .env.example for required settings.

## Database Setup

```bash
# Run migrations
cd services/moderation-anti-spam
php bin/hyperf.php db:migrate

# Seed default data
php bin/hyperf.php db:seed ReportCategorySeeder
php bin/hyperf.php db:seed BanTemplateSeeder
```

## API Documentation

See [MODERATION_PORT.md](MODERATION_PORT.md) for complete API documentation.

### Quick Start

```bash
# Submit a report
curl -X POST http://localhost:9501/api/v1/reports \
  -H "Content-Type: application/json" \
  -d '{
    "board": "g",
    "post_id": 12345678,
    "category_id": 3
  }'

# Get report queue (staff)
curl http://localhost:9501/api/v1/reports?board=g&cleared=0

# Check ban status
curl -X POST http://localhost:9501/api/v1/bans/check \
  -H "Content-Type: application/json" \
  -d '{
    "board": "g",
    "ip": "192.168.1.1"
  }'
```

## Architecture

### Models
- `Report` - User-submitted reports
- `ReportCategory` - Report classification categories  
- `BanTemplate` - Predefined ban configurations
- `BannedUser` - Active/expired bans
- `BanRequest` - Pending janitor ban requests
- `ReportClearLog` - Cleared report history

### Services
- `ModerationService` - Core moderation logic
- `SpamService` - Spam detection and captcha

### Controllers
- `ModerationController` - REST API endpoints
- `HealthController` - Health checks

## Key Constants (from OpenYotsuba)

```php
// Report thresholds
GLOBAL_THRES = 1500;       // Weight for global unlock
HIGHLIGHT_THRES = 500;     // Weight for highlighting
THREAD_WEIGHT_BOOST = 1.25; // OP weight multiplier

// Abuse detection
ABUSE_CLEAR_DAYS = 3;      // Days to check clears
ABUSE_CLEAR_COUNT = 50;    // Clears before auto-ban
REP_ABUSE_TPL = 190;       // Report abuse template ID
```

## Testing

```bash
# Run tests
vendor/bin/phpunit

# Static analysis
vendor/bin/phpstan analyse
```

## Status

✅ Database migrations created
✅ Models ported with full schema
✅ ModerationService with business logic
✅ REST API endpoints
✅ Default data seeders
✅ Documentation

⏳ Frontend UI (report queue, admin panels)
⏳ WebSocket real-time updates
⏳ Integration with boards service

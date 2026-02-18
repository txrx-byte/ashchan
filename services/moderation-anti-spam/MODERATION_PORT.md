# Moderation/Anti-spam Service - Port from OpenYotsuba

This service has been ported from 4chan's OpenYotsuba codebase to Ashchan's Hyperf microservices architecture.

## Ported Features

### Reports System
- **Report submission** with categories and weight-based prioritization
- **Report queue** with filtering by board, cleared status, and weight
- **Report categories** with board-specific rules and restrictions
- **Weight system** with thread boost and global unlock thresholds

### Ban System
- **Ban templates** for standardized enforcement
- **Ban requests** (janitor → mod approval workflow)
- **Global/local/unappealable** ban types
- **Warning system** (short-duration bans as warnings)
- **4chan Pass support** for cross-board bans

### Moderation Workflow
- **Janitor stats** tracking for approval/denial rates
- **Report abuse detection** with automatic enforcement
- **Cleared report logging** for abuse pattern detection

## Database Schema

### Tables Ported
1. `reports` - User-submitted reports
2. `report_categories` - Report classification categories
3. `ban_templates` - Predefined ban configurations
4. `banned_users` - Active and expired bans
5. `ban_requests` - Pending ban requests from janitors
6. `report_clear_log` - Cleared report history for abuse detection
7. `janitor_stats` - Janitor performance statistics

### Migrations
```bash
cd services/moderation-anti-spam
php bin/hyperf.php db:migrate
```

### Seeders
```bash
# Seed default report categories
php bin/hyperf.php db:seed ReportCategorySeeder

# Seed default ban templates
php bin/hyperf.php db:seed BanTemplateSeeder
```

## API Endpoints

### Public Endpoints

#### Submit Report
```http
POST /api/v1/reports
Content-Type: application/json

{
  "post_id": 12345678,
  "board": "g",
  "category_id": 3,
  "captcha_token": "optional"
}
```

#### Get Report Categories
```http
GET /api/v1/report-categories?board=g&ws=0
```

### Staff Endpoints (Authentication Required)

#### Get Report Queue
```http
GET /api/v1/reports?board=g&cleared=0&page=1
```

#### Clear Report
```http
POST /api/v1/reports/{id}/clear
Content-Type: application/json

{
  "staff_username": "janitor123"
}
```

#### Create Ban Request (Janitor+)
```http
POST /api/v1/ban-requests
Content-Type: application/json

{
  "board": "g",
  "post_no": 12345678,
  "janitor_username": "janitor123",
  "template_id": 5,
  "reason": "Spam posting",
  "post_data": {...}
}
```

#### Approve/Deny Ban Request (Mod+)
```http
POST /api/v1/ban-requests/{id}/approve
POST /api/v1/ban-requests/{id}/deny
```

#### Get/Create Ban Templates (Manager+)
```http
GET /api/v1/ban-templates
POST /api/v1/ban-templates
PUT /api/v1/ban-templates/{id}
```

#### Check Ban Status
```http
POST /api/v1/bans/check
Content-Type: application/json

{
  "board": "g",
  "ip": "192.168.1.1",
  "pass_id": "optional"
}
```

## Key Differences from OpenYotsuba

### Architecture
- **Hyperf framework** instead of raw PHP
- **PostgreSQL** instead of MySQL
- **PSR-compliant** code with dependency injection
- **RESTful API** instead of server-rendered HTML

### Security
- **IP encryption** at rest (implement in production)
- **Hashed reporter IPs** for privacy
- **Role-based access control** via middleware

### Scalability
- **Connection pooling** for database
- **Async-ready** architecture
- **Horizontal scaling** support

## Configuration

### Environment Variables (.env)
```env
DB_DRIVER=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=ashchan
DB_USER=ashchan
DB_PASSWORD=ashchan

# Report settings
REPORT_GLOBAL_THRES=1500
REPORT_HIGHLIGHT_THRES=500
REPORT_THREAD_WEIGHT_BOOST=1.25

# Abuse detection
ABUSE_CLEAR_DAYS=3
ABUSE_CLEAR_COUNT=50
```

## Testing

```bash
cd services/moderation-anti-spam

# Run tests
vendor/bin/phpunit

# Static analysis
vendor/bin/phpstan analyse
```

## TODO: Future Enhancements

1. **WebSocket support** for real-time report queue updates
2. **Image hash matching** for automatic illegal content detection
3. **Machine learning** spam scoring integration
4. **Audit logging** for all moderation actions
5. **Appeals system** for banned users
6. **Rate limiting** per IP/pass for report submission
7. **Geographic ban** support (IP range bans)
8. **DNSBL integration** for known bad IPs

## References

- OpenYotsuba: `/home/abrookstgz/OpenYotsuba/reports/`
- OpenYotsuba Admin: `/home/abrookstgz/OpenYotsuba/admin/`
- OpenYotsuba Manager: `/home/abrookstgz/OpenYotsuba/admin/manager/`

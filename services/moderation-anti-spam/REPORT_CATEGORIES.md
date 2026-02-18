# Report Categories - Ported from OpenYotsuba

## Source
`/home/abrookstgz/OpenYotsuba/board/setup.php` (line 157)

## Default Category

### ID 31: Illegal Content
- **Title:** "This post violates applicable law."
- **Weight:** 1000.00 (maximum severity)
- **Board:** Global (all boards)
- **Filtered:** No

This is the **canonical illegal content category** used across 4chan. It is hardcoded with ID 31 in the original system.

## Category System

### Weight System

Report categories use a weight system to prioritize moderation queue:

| Weight | Priority | Description |
|--------|----------|-------------|
| 1000+  | Critical | Illegal content (ID 31) |
| 500+   | High     | Severe rule violations |
| 100-500| Medium   | Standard violations |
| <100   | Low      | Minor violations |

### Thresholds (from OpenYotsuba ReportQueue.php)

```php
const GLOBAL_THRES = 1500;       // Report unlocked globally
const HIGHLIGHT_THRES = 500;     // Report highlighted
const THREAD_WEIGHT_BOOST = 1.25; // OP weight multiplier
```

### Category Options

Categories can be configured with:

- **Board:** Specific board, global (empty), `_ws_` (worksafe), `_nws_` (NSFW)
- **Weight:** Severity multiplier
- **Exclude boards:** Comma-separated list of boards to exclude
- **Filtered:** Threshold for abuse filtering (0 = disabled)
- **OP only:** Only applies to thread starters
- **Reply only:** Only applies to replies
- **Image only:** Only applies to posts with images

## Usage

### Submitting a Report

```http
POST /api/v1/reports
Content-Type: application/json

{
  "board": "g",
  "post_id": 12345678,
  "category_id": 31
}
```

### Getting Categories for Report Form

```http
GET /api/v1/report-categories?board=g&ws=0
```

Response:
```json
{
  "categories": {
    "rule": [
      {
        "id": 1,
        "title": "Spam",
        "weight": 50.00,
        "board": ""
      }
    ],
    "illegal": {
      "id": 31,
      "title": "This post violates applicable law.",
      "weight": 1000.00,
      "board": ""
    }
  }
}
```

## Adding Custom Categories

Categories can be added via the admin interface or directly:

```php
use App\Model\ReportCategory;

ReportCategory::create([
    'board' => 'g',
    'title' => 'Off-topic',
    'weight' => 25.00,
    'exclude_boards' => '',
    'filtered' => 0,
    'op_only' => 0,
    'reply_only' => 0,
    'image_only' => 0,
]);
```

## Seeding

```bash
php bin/hyperf.php db:seed ReportCategorySeeder
```

This inserts the canonical ID 31 "Illegal" category from 4chan.

## Moderation Queue Behavior

Reports are displayed in the queue with:

1. **Weight aggregation:** Multiple reports on same post sum weights
2. **Thread boost:** OP reports get 1.25x weight
3. **Global unlock:** Posts with 1500+ weight visible to all janitors
4. **Highlight:** Posts with 500+ weight highlighted

## Abuse Filtering

Categories with `filtered > 0` will:
- Track cleared reports per IP
- Reduce weight to 0.5 if IP has excessive clears
- Help identify report abuse patterns

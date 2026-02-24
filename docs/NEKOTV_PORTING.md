# NekotV Porting Plan — meguca → ashchan

## Summary

NekotV is meguca's per-thread synchronized video player that lets all users in a thread watch videos together in real time. This document describes the complete porting plan from meguca's Go/TypeScript/protobuf implementation to ashchan's PHP 8.3/Swoole/vanilla-JS architecture.

**Status:** Implementation complete. Cross-worker shared state refactored to use Swoole Tables.

---

## 1. Architecture Mapping

| Concern | meguca | ashchan |
|---------|--------|---------|
| Server runtime | Go goroutines, channels | Swoole workers, coroutines |
| Wire format | Protocol Buffers (binary) | JSON + type byte 0x10 (binary) |
| Persistence | RocksDB (permanent) | Redis with 24h TTL + 1s debounced writes |
| Client language | TypeScript (bundled) | Vanilla ES5 (IIFE pattern, no build tools) |
| Timer precision | `time.Now()` nanoseconds | `microtime(true)` microseconds |
| Cross-worker IPC | N/A (single process) | `Swoole\Server::sendMessage()` + `PipeMessage` |
| Command parsing | Go regex on post body | PHP regex on post body (same regex) |
| Metadata fetch | Go `http.Get` + `exec.Command` | cURL + `Swoole\Coroutine\System::exec()` |

## 2. Server-Side Files

All files are under `services/api-gateway/app/NekotV/`:

| File | Purpose | meguca Equivalent |
|------|---------|-------------------|
| `VideoType.php` | PHP 8.1 enum (RAW=0, YOUTUBE=1, TWITCH=2, IFRAME=3, TIKTOK=4, TIKTOK_LIVE=5) | `pb/nekotv.proto` VideoType |
| `VideoItem.php` | Readonly value object with `JsonSerializable` | `pb/nekotv.proto` VideoItem |
| `VideoTimer.php` | Server-authoritative playback timer (Swoole Table backed) | `websockets/feeds/nekotv/timer.go` |
| `VideoList.php` | Ordered playlist with position tracking (Swoole Table backed) | `websockets/feeds/nekotv/video_list.go` |
| `NekotVEvent.php` | Static factory for 15 event types | Proto `WebSocketMessage` oneof |
| `NekotVFeed.php` | Per-thread feed (clients, timer, sync, auto-skip, persistence) | `websockets/feeds/neko_tv.go` NekoTVFeed |
| `NekotVFeedManager.php` | Worker-local feed registry (subscribe/unsubscribe/dispatch) | `websockets/feeds/feeds.go` nekotvFeeds map |
| `MetadataFetcher.php` | YouTube/Twitch/Kick/TikTok/raw video metadata | `websockets/feeds/nekotv/yt_api.go`, `ytdlp.go`, `tiktok.go` |
| `MediaCommandType.php` | Enum (ADD_VIDEO=1..SET_RATE=8) | `common/vars.go` MediaCommandType |
| `MediaCommand.php` | Value object (type, args) | `common/vars.go` MediaCommand |
| `CommandParser.php` | Regex parser for `.play`, `.skip`, `.pause`, etc. | `common/vars.go` MediaComRegexp |

### Modified Existing Files

| File | Changes |
|------|---------|
| `WebSocket/PipeMessage.php` | Added `TYPE_NEKOTV_BROADCAST` constant |
| `WebSocket/WebSocketController.php` | Added NekotVFeedManager lifecycle, onClose unsubscribe, onPipeMessage routing, metrics |
| `WebSocket/MessageHandler.php` | Added binary type 0x10 routing, `handleNekotV()` method |
| `WebSocket/Handler/ClosePostHandler.php` | Added NekotVFeedManager param, command dispatch after post close |
| `Controller/Staff/NekotVController.php` | New controller for playlist lock/unlock API |
| `config/routes.php` | Added NekotV lock API routes |

### Cross-Worker Shared State Files

| File | Purpose |
|------|---------|
| `NekotV/NekotVTables.php` | Static holder for Swoole Table instances (timer + playlist) |
| `Listener/NekotVTableListener.php` | `#[Listener]` on `BeforeMainServerStart` — creates tables before fork |

## 3. Wire Protocol

### Binary Format

```
Server → Client:  [JSON payload (UTF-8 bytes)] [0x10]
Client → Server:  [action: 0x01=subscribe, 0x00=unsubscribe] [0x10]
```

The type byte `0x10` is appended as the last byte, consistent with ashchan's existing binary protocol convention where the type byte is always the last byte of the frame.

### Event Types (JSON `event` field)

| Event | Payload | Direction |
|-------|---------|-----------|
| `connected` | `{video_list, item_pos, is_open, time, paused, rate}` | S→C |
| `add_video` | `{item, at_end}` | S→C |
| `remove_video` | `{url}` | S→C |
| `skip_video` | `{url}` | S→C |
| `pause` | `{time}` | S→C |
| `play` | `{time}` | S→C |
| `time_sync` | `{time, paused, rate}` | S→C (1s interval) |
| `set_time` | `{time}` | S→C |
| `set_rate` | `{rate}` | S→C |
| `rewind` | `{time}` | S→C |
| `play_item` | `{pos}` | S→C |
| `set_next_item` | `{pos}` | S→C |
| `update_playlist` | `{items}` | S→C |
| `toggle_lock` | `{is_open}` | S→C |
| `clear_playlist` | `{}` | S→C |

## 4. Client-Side Files

All files are under `frontend/static/js/nekotv/`:

| File | Purpose | meguca Equivalent |
|------|---------|-------------------|
| `protocol.js` | Wire protocol encode/decode, constants | `client/connection.ts` (message.nekoTV) |
| `videolist.js` | Client-side playlist data structure | `client/nekotv/videolist.ts` |
| `players.js` | YouTube, Twitch, Raw, IFrame, TikTok player implementations | `client/nekotv/players/*.ts` (6 files) |
| `player.js` | Main player controller, delegates to correct player | `client/nekotv/player.ts` |
| `playlist.js` | Playlist DOM rendering, time display | `client/nekotv/playlist.ts` |
| `theater.js` | Theater mode (full-viewport split view) | `client/nekotv/theaterMode.ts` |
| `handlers.js` | Server event dispatch to player methods | `client/nekotv/handlers.ts` |
| `index.js` | Entry point, WebSocket integration, UI controls | `client/nekotv/nekotv.ts` |

### CSS

| File | Purpose |
|------|---------|
| `frontend/static/css/nekotv.css` | Watch panel, playlist, theater mode, responsive | 

### Template Changes

- `frontend/templates/thread.html`: Added NekotV script tags, CSS link, banner icon, watch panel HTML

## 5. Data Flow

### Post-Based Commands

```
User types ".play https://youtube.com/watch?v=xxx" in a post body
→ User closes post (ClosePost type 05)
→ ClosePostHandler::handle() closes the post via boards-threads-posts service
→ ClosePostHandler::dispatchNekotVCommands() parses body with CommandParser
→ NekotVFeedManager::handleMediaCommand() dispatches to NekotVFeed
→ MetadataFetcher::fetch() retrieves video metadata (in coroutine)
→ NekotVFeed::addVideo() adds to playlist, broadcasts ADD_VIDEO event
→ All subscribed clients receive the event and update their player
```

### WebSocket Subscription

```
Client connects to /api/socket, sends sync message
→ Client sends binary [0x01, 0x10] to subscribe
→ MessageHandler::handleNekotV() calls NekotVFeedManager::subscribe()
→ NekotVFeed created (or restored from Redis), sends CONNECTED event
→ Client's NekotV.Handlers.handleMessage() processes CONNECTED
→ Player loads playlist and syncs to current time
```

### Time Synchronization

```
NekotVFeed starts 1s Swoole::tick timer
→ Every second, broadcasts TIME_SYNC {time, paused, rate}
→ Client receives TIME_SYNC, checks drift
→ If |drift| > 1.6s, client seeks to server time + 0.5s
→ If near video end (time > duration - 0.01s), server auto-skips
```

## 6. Configuration

Environment variables in `.env.example`:

| Variable | Default | Description |
|----------|---------|-------------|
| `NEKOTV_ENABLED` | `false` | Feature flag |
| `YOUTUBE_API_KEY` | (required) | YouTube Data API v3 key |
| `NEKOTV_MAX_PLAYLIST_SIZE` | `50` | Maximum videos per playlist |
| `NEKOTV_SYNC_INTERVAL` | `1000` | Time sync broadcast interval (ms) |
| `NEKOTV_MP4_WHITELIST` | (empty) | Comma-separated domains for raw MP4/WebM |

## 7. Redis Keys

| Key Pattern | TTL | Description |
|-------------|-----|-------------|
| `nekotv:state:{threadId}` | 24h | Serialized feed state (timer + playlist) |
| `nekotv:lock:{threadId}` | 24h | Playlist lock flag (set by staff) |

## 8. Staff API

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/v1/nekotv/{threadId}/lock` | POST | Staff | Set lock state (`{"locked": true}`) |
| `/api/v1/nekotv/{threadId}/lock` | GET | Staff | Check lock state |

## 9. Key Differences from meguca

1. **No protobuf** — ashchan uses JSON for all NekotV events. This simplifies the implementation since ashchan has no protobuf tooling, at the cost of slightly larger wire frames.

2. **Redis instead of RocksDB** — Feed state is persisted to Redis with 24h TTL and 1s debounced writes. This is consistent with ashchan's existing caching architecture.

3. **Multi-worker architecture** — meguca runs a single Go process; ashchan runs multiple Swoole workers. NekotV broadcasts must fan out to all workers via `PipeMessage::TYPE_NEKOTV_BROADCAST`.

4. **No vote-skip** — The meguca vote-skip feature (`voteskip.go`) is not ported because ashchan's anonymous model makes vote integrity difficult.

5. **No TikTok Live** — `TIKTOK_LIVE=5` enum exists but is not actively used in the metadata fetcher (TikTok changed their API).

6. **Vanilla ES5** — meguca uses TypeScript with a bundler; ashchan uses vanilla ES5 with IIFE pattern and no build tools, consistent with the existing livepost JS.

## 10. Cross-Worker Shared State Architecture

### Problem

Swoole forks multiple worker processes. Each worker has its own memory space.
Without shared state, two clients connected to different workers would see
different timer positions and playlist contents — a critical desynchronization bug.

meguca avoids this because Go runs a single process where all goroutines share
the same memory. ashchan must solve this explicitly.

### Solution: Swoole Tables

Swoole Tables are fixed-schema shared-memory hash maps allocated **before**
`$server->start()` (before workers fork). All workers then read/write the same
physical memory with lock-free atomic access.

```
┌──────────────────────────────────────────────────────────────┐
│                   Shared Memory (pre-fork)                    │
│                                                              │
│  NekotVTables::timerTable()     ← Swoole\Table (1024 rows)  │
│  ┌─────────┬────────────┬─────────────┬───────────┬──────┐  │
│  │ key     │ start_time │ pause_start │ rate_start│ rate │  │
│  │ (thId)  │ FLOAT/8    │ FLOAT/8     │ FLOAT/8   │FLOAT │  │
│  │         │ is_started │ rebake_at   │           │      │  │
│  │         │ INT/1      │ FLOAT/8     │           │      │  │
│  └─────────┴────────────┴─────────────┴───────────┴──────┘  │
│                                                              │
│  NekotVTables::playlistTable()  ← Swoole\Table (1024 rows)  │
│  ┌─────────┬──────────────┬─────┬─────────┬────────┐        │
│  │ key     │ data         │ pos │ is_open │ length │        │
│  │ (thId)  │ STRING/64KB  │INT/4│ INT/1   │ INT/4  │        │
│  │         │ (JSON blob)  │     │         │        │        │
│  └─────────┴──────────────┴─────┴─────────┴────────┘        │
└──────────────────────┬───────────────────────────────────────┘
                       │  fork()
        ┌──────────────┼──────────────┐
        ▼              ▼              ▼
   Worker 0        Worker 1       Worker N
   VideoTimer(id)  VideoTimer(id)  VideoTimer(id)
   VideoList(id)   VideoList(id)   VideoList(id)
     └─── all read/write same table rows ─────┘
```

### Lifecycle

1. **BeforeMainServerStart** — `NekotVTableListener` calls `NekotVTables::init()`,
   creating both tables in the master process.
2. **fork()** — Swoole forks `worker_num` child processes. Each inherits the
   shared memory pages.
3. **Per-worker** — `NekotVFeedManager` creates `VideoTimer($threadId)` and
   `VideoList($threadId)` instances. Despite being separate PHP objects, they
   all address the same row in shared memory via the string key `(string)$threadId`.
4. **destroy()** — When a feed has no clients, `destroy()` deletes the timer and
   playlist rows from the Swoole Tables and flushes state to Redis.

### Timer Table Schema

| Column | Type | Size | Description |
|--------|------|------|-------------|
| `start_time` | FLOAT | 8 | `microtime(true)` when playback started |
| `pause_start` | FLOAT | 8 | `microtime(true)` when pause began (0.0 = not paused) |
| `rate_start` | FLOAT | 8 | `microtime(true)` when rate was last changed |
| `rate` | FLOAT | 8 | Playback speed multiplier (0.0625 – 16.0) |
| `is_started` | INT | 1 | Boolean: whether the timer is running |
| `rebake_at` | FLOAT | 8 | Next scheduled re-bake timestamp |

### Playlist Table Schema

| Column | Type | Size | Description |
|--------|------|------|-------------|
| `data` | STRING | 65536 | JSON-serialized array of VideoItem objects |
| `pos` | INT | 4 | Current playback position index |
| `is_open` | INT | 1 | Boolean: playlist unlocked for user edits |
| `length` | INT | 4 | Number of items (avoids deserializing JSON for length checks) |

### Input Validation

| Method | Constraint | Exception |
|--------|-----------|-----------|
| `VideoTimer::setRate($rate)` | `0.0625 ≤ rate ≤ 16.0` | `InvalidArgumentException` |
| `VideoTimer::setTime($seconds)` | `seconds ≥ 0` | `InvalidArgumentException` |

Callers in `NekotVFeed` catch `InvalidArgumentException` and log at debug level,
preventing invalid values from propagating to shared state or broadcasts.

### Float Drift Protection

Repeated `microtime(true)` arithmetic (`now - startTime - rateOffset + rateOffset * rate - pauseOffset`)
accumulates IEEE 754 floating-point rounding errors over long sessions. After 24+ hours,
aggregate drift can reach ~1ms, which compounds with rate changes.

**Solution — periodic re-baking:**

1. `VideoTimer` stores a `rebake_at` timestamp (set to `now + 3600` on start/re-bake).
2. `NekotVFeed` runs a check timer every 5 minutes (`REBAKE_CHECK_INTERVAL_MS = 300000`).
3. When `needsReBake()` returns true, `reBake()` collapses all arithmetic:
   - Captures `currentTime = getTime()`.
   - Writes `start_time = now - currentTime`, resetting `rate_start` and `pause_start`.
   - This makes `getTime()` equal `now - start_time` with zero accumulated offset.

### Rate Change Broadcasting

When `setRate()` is called:
1. `NekotVFeed::setRate()` validates and applies via `VideoTimer::setRate()`.
2. Immediately broadcasts `NekotVEvent::setRate($rate)` to all workers.
3. Clients receive the event and adjust their player speed without waiting for the next 1s tick.

The `.rate <value>` post command is supported via `CommandParser` → `MediaCommandType::SET_RATE`.

## 11. Testing

```bash
# Static analysis (PHPStan level 10)
cd services/api-gateway && composer phpstan

# Run tests
cd services/api-gateway && composer test

# Manual testing
# 1. Set NEKOTV_ENABLED=true in .env
# 2. Set YOUTUBE_API_KEY=<your-key> in .env
# 3. Start services: make up
# 4. Open a thread page in browser
# 5. Click the 📺 icon to enable NekotV
# 6. Post a comment containing: .play https://www.youtube.com/watch?v=dQw4w9WgXcQ
# 7. Verify the video appears in the watch panel
# 8. Open the same thread in another tab — verify sync
```

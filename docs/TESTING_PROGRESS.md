# Ashchan Testing Progress

**Last Updated:** February 28, 2026

---

## Overall Status

| Phase | Status | Files Created | Files Target | Progress |
|-------|--------|--------------|--------------|----------|
| **Phase 1: Foundation** | ✅ Complete | 8 | 8 | 100% |
| **Phase 2: Critical Services** | 🟡 In Progress | 9 | 51 | 18% |
| **Phase 3: API Gateway** | ⚪ Pending | 0 | 58 | 0% |
| **Phase 4: Completion** | ⚪ Pending | 0 | 15 | 0% |

**Total:** 17 / 132 test files (13%)

---

## Phase 1: Foundation ✅ COMPLETE

### Completed Infrastructure
- [x] PHPUnit configuration for `boards-threads-posts`
- [x] PHPUnit configuration for `moderation-anti-spam`
- [x] Test directory structures for both services
- [x] Bootstrap files with DG\BypassFinals
- [x] Composer test/phpstan scripts
- [x] Makefile targets (`test`, `test-coverage`, `phpstan-all`, `ci-test`)
- [x] Comprehensive TESTING_PLAN.md documentation

### Files Created (8)
1. `services/boards-threads-posts/phpunit.xml`
2. `services/moderation-anti-spam/phpunit.xml`
3. `services/boards-threads-posts/tests/bootstrap.php`
4. `services/moderation-anti-spam/tests/bootstrap.php`
5. `docs/TESTING_PLAN.md`
6. `.github/workflows/test.yml` (requires manual upload)
7. `Makefile` (test targets added)
8. Service composer.json files (test scripts added)

---

## Phase 2: Critical Services 🟡 IN PROGRESS

### Moderation-Anti-Spam Service (9/28 files)

#### Services (2/9)
- [x] `PiiEncryptionServiceTest.php` - 16 tests
  - Encryption/decryption round-trip
  - Key management and derivation
  - Error handling (tampered data, wrong key)
  - Memory wiping
  
- [x] `SiteConfigServiceTest.php` - 17 tests
  - Redis caching behavior
  - Type coercion (string, int, float, bool, array)
  - Graceful degradation on cache miss
  - Default value handling

#### Controllers (1/6)
- [x] `HealthControllerTest.php` - 3 tests
  - Health check endpoint
  - Response format validation
  - HTTP status codes

#### Models (4/7)
- [x] `BanTemplateTest.php` - 18 tests
  - Fillable properties
  - Type casts
  - Business logic (isWarning, isPermanent, ban length)
  - Constants and scopes
  
- [x] `ReportTest.php` - 20 tests
  - Weight thresholds
  - JSON hydration
  - Category names
  - Scopes and relationships
  
- [x] `ReportCategoryTest.php` - 20 tests
  - Board applicability logic
  - OP/reply/image-only filtering
  - Worksafe/NSFW board filtering
  - Scopes

- [ ] `BannedUserTest.php` - **TODO**
- [ ] `BanRequestTest.php` - **TODO**
- [ ] `ModerationDecisionTest.php` - **TODO**
- [ ] `ReportClearLogTest.php` - **TODO**

#### Processes (0/1)
- [ ] `PostScoringProcessTest.php` - **TODO**

#### Remaining Services to Test
- [ ] `SpamServiceTest.php` - **CRITICAL** (multi-layer scoring)
- [ ] `ModerationServiceTest.php` - **CRITICAL** (ban workflow)
- [ ] `StopForumSpamServiceTest.php` - **TODO**
- [ ] `SpurServiceTest.php` - **TODO**
- [ ] `SfsSubmissionServiceTest.php` - **TODO**
- [ ] `IpRetentionServiceTest.php` - **TODO**
- [ ] `SiteSettingsServiceTest.php` - **TODO**

#### Remaining Controllers to Test
- [ ] `ModerationControllerTest.php` - **CRITICAL**
- [ ] `SfsQueueControllerTest.php` - **TODO**
- [ ] `SiteSettingsControllerTest.php` - **TODO**
- [ ] `SpurControllerTest.php` - **TODO**
- [ ] `StopForumSpamControllerTest.php` - **TODO**

### Boards-Threads-Posts Service (2/21 files)

#### Models (2/5)
- [x] `BoardTest.php` - 26 tests
  - Fillable properties and casts
  - NSFW/staff-only flags
  - Image/anonymous requirements
  - Scopes (slug, active, nsfw, worksafe)
  - Relationships (threads, reports)
  - Configuration methods
  
- [x] `BlotterTest.php` - 9 tests
  - Important flag logic
  - Recent entries retrieval
  - Scopes

- [ ] `ThreadTest.php` - **TODO**
- [ ] `PostTest.php` - **TODO**
- [ ] `OpenPostBodyTest.php` - **TODO**

#### Services (0/6)
- [ ] `BoardServiceTest.php` - **CRITICAL**
- [ ] `ThreadServiceTest.php` - **CRITICAL**
- [ ] `PostServiceTest.php` - **CRITICAL**
- [ ] `FourChanApiServiceTest.php` - **TODO**
- [ ] `ContentFormatterTest.php` - **TODO**
- [ ] `IpRetentionServiceTest.php` - **TODO**

#### Controllers (0/5)
- [ ] `BoardControllerTest.php` - **CRITICAL**
- [ ] `ThreadControllerTest.php` - **CRITICAL**
- [ ] `FourChanApiControllerTest.php` - **TODO**
- [ ] `LivepostControllerTest.php` - **TODO**
- [ ] `HealthControllerTest.php` - **TODO**

#### Commands (0/1)
- [ ] `PiiCleanupCommandTest.php` - **TODO**

---

## Phase 3: API Gateway Expansion ⚪ PENDING

### Priority Targets (58 files)
- [ ] Staff Controllers (14 files)
- [ ] Core Controllers (4 files)
- [ ] Services (6 files)
- [ ] WebSocket Handlers (10 files)
- [ ] Feed Generation (5 files)
- [ ] NekotV (10 files)
- [ ] Models (8 files)
- [ ] Middleware (2 files)
- [ ] Commands (6 files)

---

## Phase 4: Completion ⚪ PENDING

### Remaining Services
- [ ] auth-accounts (1 file)
- [ ] media-uploads (3 files)
- [ ] search-indexing (5 files)

### Integration Tests
- [ ] Database integration tests
- [ ] Redis integration tests
- [ ] Event bus integration tests

---

## Test Quality Metrics

### Coverage by Test Type

| Service | Unit Tests | Feature Tests | Integration Tests | Total |
|---------|-----------|---------------|-------------------|-------|
| moderation-anti-spam | 7 | 0 | 0 | 7 |
| boards-threads-posts | 2 | 1 | 0 | 3 |
| api-gateway | 11 | 0 | 0 | 11 |
| auth-accounts | 10 | 0 | 0 | 10 |
| media-uploads | 8 | 1 | 0 | 9 |
| search-indexing | 8 | 0 | 0 | 8 |

**Grand Total:** 48 test files existing (36%)

### Test Count by Category

| Category | Count |
|----------|-------|
| Service Tests | 11 |
| Model Tests | 13 |
| Controller Tests | 14 |
| Middleware Tests | 4 |
| Process Tests | 0 |
| Command Tests | 0 |
| Feature Tests | 2 |
| Integration Tests | 0 |

---

## Next Steps (Immediate Priority)

### Week 1-2: Core Business Logic
1. **SpamServiceTest.php** (moderation-anti-spam)
   - Multi-layer spam scoring
   - Rate limiting with sliding window
   - Content risk scoring
   - Captcha generation/verification

2. **ModerationServiceTest.php** (moderation-anti-spam)
   - Report creation and weight calculation
   - Ban request workflow
   - Ban creation from templates
   - Ban checking logic

3. **BoardServiceTest.php** (boards-threads-posts)
   - Board CRUD operations
   - Board configuration

4. **ThreadServiceTest.php** (boards-threads-posts)
   - Thread creation/deletion
   - Thread bumping logic

### Week 3-4: Controllers and Models
1. Complete remaining model tests
2. Add controller tests for critical endpoints
3. Add feature tests for key workflows

---

## Running Tests

```bash
# Run all tests
make test

# Run tests for specific service
cd services/moderation-anti-spam && composer test
cd services/boards-threads-posts && composer test

# Run with coverage
make test-coverage

# Run PHPStan analysis
make phpstan-all
```

---

## Commits

1. `7d748db` - test: add testing infrastructure for boards-threads-posts and moderation-anti-spam
2. `d77b69b` - test: add initial unit tests for moderation and boards services (9 test files)

---

## Notes

- All tests use PHPUnit 10.0 with strict settings (failOnRisky, failOnWarning)
- DG\BypassFinals enabled for mocking final classes
- Tests follow project conventions with `@covers` annotations
- Test namespaces match source code structure
- Comprehensive edge case coverage prioritized

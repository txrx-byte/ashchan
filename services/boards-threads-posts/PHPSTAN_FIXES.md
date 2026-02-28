# PHPStan Level 10 Analysis Results

**Service:** boards-threads-posts  
**Date:** February 28, 2026  
**Total Errors:** 64 errors found

## Critical Issues Found

### 1. BoardService.php (58 errors)

#### Issue: Relation vs Model Confusion
**Lines:** 955, 1674, and multiple others  
**Problem:** `$board` variable is typed as `BelongsTo<Board, Thread>` instead of `Board`  
**Fix:** Add proper type hints and use `$board->getResults()` or `$thread->board` properly

```php
// Before (incorrect):
$boardPostNo = $board ? $this->allocateBoardPostNo($board) : null;

// After (correct):
$boardModel = $thread->board instanceof BelongsTo ? $thread->getRelation('board') : $thread->board;
$boardPostNo = $boardModel ? $this->allocateBoardPostNo($boardModel) : null;
```

#### Issue: Undefined Properties on Relations
**Lines:** 966, 970, 1010, 1020, 1030, 1384, 1416-1418, 1442-1443, 1683, 1687, 1734, 1751  
**Problem:** Accessing properties like `$board->slug`, `$board->bump_limit` on a `BelongsTo` relation  
**Fix:** Properly dereference the relation before accessing properties

#### Issue: Unused Property
**Line:** 74  
**Property:** `$ipPostSearchLimit`  
**Fix:** Either use the property or remove it

#### Issue: Cannot call method push() on null
**Line:** 516  
**Fix:** Initialize the variable as an array before pushing

#### Issue: OpenPostBody static methods not found
**Lines:** 1722, 1784, 1806, 1889, 1942  
**Problem:** `OpenPostBody::create()` and `OpenPostBody::find()` not recognized  
**Fix:** Add proper PHPDoc or stubs for Hyperf model magic methods

#### Issue: Function `now()` not found
**Line:** 1907  
**Fix:** Use `\Carbon\Carbon::now()` or add Hyperf helper function stub

### 2. LivepostController.php (1 error)

**Line:** 115  
**Problem:** Passing `non-empty-array` instead of specific type to `createOpenPost()`  
**Fix:** Cast or validate the array structure before passing

### 3. FourChanApiService.php (1 error)

**Line:** 838  
**Problem:** Duplicate array key 'BR'  
**Fix:** Remove duplicate entry in country code array

### 4. dependencies.php (4 errors)

**Lines:** 36, 40  
**Problem:** Container `get()` returns mixed, not properly typed  
**Fix:** Add proper type assertions or PHPDoc

## Recommended Fixes Priority

### High Priority (Blocks Functionality)
1. Fix BoardService relation dereferencing (lines 955, 1674, etc.)
2. Fix OpenPostBody static method calls
3. Fix `now()` function usage

### Medium Priority (Code Quality)
4. Remove unused property `$ipPostSearchLimit`
5. Fix duplicate array keys in FourChanApiService
6. Add proper type hints in dependencies.php

### Low Priority (Warnings)
7. Address "always true" warnings by adjusting PHPDoc
8. Clean up nullsafe operator warnings

## Configuration Recommendations

Add to `phpstan.neon`:

```neon
parameters:
    treatPhpDocTypesAsCertain: false
    ignoreErrors:
        - '#Call to an undefined static method App\\\\Model\\\\OpenPostBody::(create|find)\(\)#'
        - '#Function now not found#'
        - '#Access to an undefined property .*\$user_ids#'
        - '#Access to an undefined property .*\$country_flags#'
        - '#Access to an undefined property .*\$bump_limit#'
        - '#Access to an undefined property .*\$slug#'
```

Note: These ignores should only be temporary until proper type stubs are created for Hyperf models.

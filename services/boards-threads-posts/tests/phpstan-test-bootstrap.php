<?php
declare(strict_types=1);

/**
 * PHPStan Bootstrap for Tests
 *
 * This file is loaded by PHPStan before static analysis of test files.
 * It is NOT the same as the PHPUnit bootstrap (tests/bootstrap.php).
 *
 * Purpose:
 * - Load test helpers and stubs for better type inference
 * - Define constants used in test files
 * - Register test-specific class aliases
 *
 * Note: DG\BypassFinals is NOT needed here - PHPStan analyzes code statically
 * and doesn't execute the code. DG\BypassFinals is only needed at runtime
 * for PHPUnit test execution.
 *
 * @see tests/bootstrap.php For PHPUnit runtime bootstrap
 * @see phpunit.xml For PHPUnit configuration
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// ============================================================================
// TEST ENVIRONMENT CONSTANTS
// ============================================================================

if (!defined('ASHCHAN_TEST_ENV')) {
    define('ASHCHAN_TEST_ENV', true);
}

if (!defined('ASHCHAN_TEST_TEMP_DIR')) {
    define('ASHCHAN_TEST_TEMP_DIR', sys_get_temp_dir() . '/ashchan-tests');
}

// Create test temp directory if it doesn't exist
if (!is_dir(ASHCHAN_TEST_TEMP_DIR)) {
    mkdir(ASHCHAN_TEST_TEMP_DIR, 0755, true);
}

// ============================================================================
// TEST HELPER FUNCTIONS
// ============================================================================

/**
 * Get a unique test identifier for isolation.
 *
 * @param string $prefix Optional prefix for the identifier
 */
function test_id(string $prefix = ''): string
{
    static $counter = 0;
    $counter++;
    return $prefix . getmypid() . '-' . time() . '-' . $counter;
}

/**
 * Create a temporary test file with content.
 *
 * @param string $content File content
 * @param string $extension File extension (default: 'tmp')
 * @return string Path to the temporary file
 */
function create_test_file(string $content, string $extension = 'tmp'): string
{
    $path = ASHCHAN_TEST_TEMP_DIR . '/test_' . test_id() . '.' . $extension;
    file_put_contents($path, $content);
    return $path;
}

/**
 * Clean up a test file.
 *
 * @param string $path Path to the file to remove
 */
function remove_test_file(string $path): void
{
    if (file_exists($path)) {
        unlink($path);
    }
}

// ============================================================================
// TEST STUB REGISTRATIONS
// ============================================================================

// Register test stub classes if they exist
$stubDir = __DIR__ . '/Stub';
if (is_dir($stubDir)) {
    foreach (glob($stubDir . '/*.php') as $stubFile) {
        require_once $stubFile;
    }
}

// ============================================================================
// HYPERF TEST CONFIGURATION
// ============================================================================

// Set Hyperf to testing mode
if (!getenv('APP_ENV')) {
    putenv('APP_ENV=testing');
}

// Disable Hyperf watchers in tests
if (!getenv('HYPERF_WATCHER_ENABLE')) {
    putenv('HYPERF_WATCHER_ENABLE=false');
}

// ============================================================================
// ENCRYPTION KEYS FOR TESTING
// ============================================================================

// Set test encryption keys (never use these in production!)
if (!getenv('PII_ENCRYPTION_KEY')) {
    putenv('PII_ENCRYPTION_KEY=test-pii-encryption-key-32bytes!!');
}

if (!getenv('IP_HMAC_KEY')) {
    putenv('IP_HMAC_KEY=test-hmac-key-for-unit-tests!');
}

// ============================================================================
// CLEANUP REGISTRATION
// ============================================================================

/**
 * Register shutdown function to clean up test artifacts.
 *
 * This ensures temporary files are removed even if tests crash.
 */
register_shutdown_function(static function (): void {
    if (defined('ASHCHAN_TEST_TEMP_DIR') && is_dir(ASHCHAN_TEST_TEMP_DIR)) {
        $files = glob(ASHCHAN_TEST_TEMP_DIR . '/*') ?: [];
        foreach ($files as $file) {
            if (is_dir($file)) {
                // Recursively remove directories
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($file, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($file);
            } else {
                unlink($file);
            }
        }
        @rmdir(ASHCHAN_TEST_TEMP_DIR);
    }
});

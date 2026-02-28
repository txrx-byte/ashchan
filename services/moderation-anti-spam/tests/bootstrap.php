<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap file for moderation-anti-spam.
 *
 * Enables bypass-finals so that `final` classes can be mocked in unit tests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

DG\BypassFinals::enable();

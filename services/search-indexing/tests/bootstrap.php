<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap file.
 *
 * Enables bypass-finals so that `final` classes (SearchService, SiteConfigService,
 * PostgresConnector, EventConsumerProcess, etc.) can be mocked in unit tests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

DG\BypassFinals::enable();

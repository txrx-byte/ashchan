<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for media-uploads tests.
 *
 * Enables DG\BypassFinals to allow mocking final classes (EventPublisher,
 * SiteConfigService, MediaService) with Mockery.
 */

require_once __DIR__ . '/../vendor/autoload.php';

DG\BypassFinals::enable();

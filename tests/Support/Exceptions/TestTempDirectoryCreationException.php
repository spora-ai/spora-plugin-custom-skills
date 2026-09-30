<?php

declare(strict_types=1);

namespace Spora\Plugins\Skeleton\Tests\Support\Exceptions;

use RuntimeException;

/**
 * Raised by test scaffolding when a temporary directory cannot be created
 * via mkdir(). Distinct from generic RuntimeException so test setup
 * failures surface under a dedicated type — easier to assert against and
 * to ignore in callers that only care about the SUT.
 */
final class TestTempDirectoryCreationException extends RuntimeException {}

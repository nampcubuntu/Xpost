<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A pasted line that could not be read as a proxy.
 *
 * Carries a message meant for whoever pasted it, because that is where it is
 * shown: the bulk import reports the line back alongside the reason rather
 * than dropping it.
 */
class InvalidProxyLine extends RuntimeException {}

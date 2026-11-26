<?php

namespace App\Services\SupportAccess;

use RuntimeException;

/** The pass is valid but its operator email belongs to an ordinary user of the site: it is never reused. */
final class SupportOperatorConflict extends RuntimeException {}

<?php

namespace App\Services\Archive\Ai;

use RuntimeException;

/**
 * A cap said no, so nothing was spent.
 *
 * Its own type because callers must tell it apart from a real failure, and the
 * difference is what somebody sees. An unreadable scan is broken and should stop
 * being retried after a few attempts; a scan that hit the month's budget or its
 * owner's daily page limit is perfectly fine and should simply wait. Treated as
 * a generic error, the second one gets marked failed three times and then
 * abandoned for a reason nobody can see.
 *
 * Nothing has been charged when this is thrown.
 */
class ArchiveSpendRefused extends RuntimeException {}

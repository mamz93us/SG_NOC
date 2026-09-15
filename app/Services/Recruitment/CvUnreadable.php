<?php

namespace App\Services\Recruitment;

use RuntimeException;

/**
 * A CV that will not become readable by trying again: not a PDF, too large,
 * password-protected or damaged. The screening is failed at once instead of
 * being retried.
 */
class CvUnreadable extends RuntimeException {}

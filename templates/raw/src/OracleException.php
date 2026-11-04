<?php

declare(strict_types=1);

namespace App;

/**
 * An Oracle failure, phrased as a plain sentence.
 *
 * The message is always one of the helper's plain sentences, never the
 * driver's own text, so a connect descriptor, username or password cannot
 * leak into an error page or a log line.
 */
class OracleException extends \RuntimeException {}

<?php

namespace App\Mandal;

use RuntimeException;

/**
 * An archive that cannot be imported, with a message for the user.
 */
final class InvalidImport extends RuntimeException {}

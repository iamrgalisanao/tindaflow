<?php

namespace App\Services\License;

use RuntimeException;

/** An offline code that was not applied. The message is written for the person who typed it; nothing was changed. */
final class OfflineCodeRefused extends RuntimeException {}

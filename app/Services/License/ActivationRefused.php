<?php

namespace App\Services\License;

use RuntimeException;

/** Activation cannot proceed. The message is written for the technician at the console and says what to do. */
final class ActivationRefused extends RuntimeException {}

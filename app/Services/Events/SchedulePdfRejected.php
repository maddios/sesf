<?php

namespace App\Services\Events;

use RuntimeException;

/** A rejected or failed schedule PDF upload. The message is safe to show to the admin. */
final class SchedulePdfRejected extends RuntimeException {}

<?php

namespace App\Services\SiteHealth;

use RuntimeException;

/**
 * MARKER-SITE-HEALTH: Google refused a read because Intake's own Google Cloud project is not
 * set up for it (an API not switched on, the project not registered with Merchant Center).
 * No shop can fix that, so it is never shown to one as their problem.
 */
class GoogleSetupNeeded extends RuntimeException {}

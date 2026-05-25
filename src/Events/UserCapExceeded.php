<?php

namespace Webpatser\ResonateUserCap\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\ResonateUserCap\PresenceCapPlugin;

/**
 * A connection was terminated because its user is at the cap.
 *
 * Dispatched from {@see PresenceCapPlugin} every
 * time a new presence subscription would push a user past the configured
 * cluster-wide cap.
 */
class UserCapExceeded
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $appId,
        public string $userId,
    ) {
        //
    }
}

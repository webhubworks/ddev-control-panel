<?php

return [
    /*
     * Absolute path to the `ddev` executable. Leave null to auto-detect: the
     * known Homebrew / install-script locations are checked first, then PATH.
     * A packaged app is launched by Finder and cannot rely on a shell PATH,
     * which is why detection does not start there.
     */
    'binary_path' => env('DDEV_BINARY_PATH'),

    /*
     * How stale the cached `ddev list` snapshot may be before opening the popup
     * triggers a background refresh. `ddev list` inspects every project's
     * containers and takes seconds on a machine with many projects, so this is
     * deliberately not instant.
     */
    'snapshot_ttl' => env('DDEV_SNAPSHOT_TTL', 30),

    /*
     * Poll interval (milliseconds) used while a refresh or a lifecycle command
     * is in flight. Polls are cheap: they only read the cache.
     */
    'poll_interval' => env('DDEV_POLL_INTERVAL', 1500),
];

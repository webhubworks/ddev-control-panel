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
     * Absolute path to the `docker` executable, used only for the event stream
     * that keeps the project list current. Same auto-detection as above.
     */
    'docker_binary_path' => env('DOCKER_BINARY_PATH'),

    /*
     * The `docker events` listener that refreshes the snapshot as soon as a
     * project changes state, whether or not the popup is open. ddev itself
     * emits nothing subscribable, so Docker's stream is the only push source.
     */
    'watch' => [
        'enabled' => env('DDEV_WATCH_ENABLED', true),

        /*
         * Milliseconds of quiet before a refresh is asked for. A single
         * `ddev start` fires dozens of events, and each refresh costs a full
         * `ddev list`, so a scan waits until the burst has settled.
         */
        'debounce' => env('DDEV_WATCH_DEBOUNCE', 750),

        /*
         * Seconds to wait before re-attaching once the stream ends, which is
         * what happens while Docker is not running.
         */
        'reconnect_delay' => env('DDEV_WATCH_RECONNECT_DELAY', 5),
    ],

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

    /*
     * Poll interval (milliseconds) used while the popup is merely open. The
     * watcher writes a new snapshot to the cache from outside the request, and
     * this is how an open popup notices. Cache reads only: no ddev involved.
     */
    'idle_poll_interval' => env('DDEV_IDLE_POLL_INTERVAL', 2000),
];

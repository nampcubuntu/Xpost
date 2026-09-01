<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    /*
     * Kept with the queue rather than on the default connection. Horizon's
     * metrics describe the queue and belong beside it, which leaves database 0
     * holding only sessions and locks -- so clearing one of those can no longer
     * take a day's queued publishing with it.
     */
    'use' => 'queue',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    /*
     * Long-wait alert thresholds, tuned per lane. Publish is the one that
     * matters: a scheduled post sitting in the queue for two minutes is a post
     * that went out late, so it alerts far sooner than the analytics lanes.
     */
    'waits' => [
        'redis:publish' => 120,
        'redis:tokens' => 120,
        'redis:fanout' => 600,
        'redis:imports' => 900,
        'redis:metrics' => 1800,
        'redis:default' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
     * Xpost runs four supervisors rather than one pool, because the work has
     * genuinely different shapes and must not share a worker budget:
     *
     *   publish  - thousands of short, network-bound jobs. The throughput lane.
     *   fanout   - a handful of long jobs writing tens of thousands of rows.
     *              Given few processes and a lot of time.
     *   tokens   - latency-sensitive: a late refresh means failed publishes.
     *   metrics  - lowest priority. Analytics falling behind costs nothing;
     *              analytics starving the publish lane costs a campaign.
     *
     * `tries` is 1 on the publish supervisor on purpose: PostPublisher already
     * owns the retry decision and reschedules the row itself, so a queue-level
     * retry would double-count attempts and could double-post.
     */
    'defaults' => [
        'publish' => [
            'connection' => 'redis',
            'queue' => ['publish'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 1,
            'maxProcesses' => 10,
            'balanceMaxShift' => 3,
            'balanceCooldown' => 2,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],

        'fanout' => [
            'connection' => 'redis',
            'queue' => ['fanout', 'imports'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 900,
            'nice' => 0,
        ],

        'tokens' => [
            'connection' => 'redis',
            'queue' => ['tokens'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 2,
            'timeout' => 60,
            'nice' => 0,
        ],

        'metrics' => [
            'connection' => 'redis',
            /*
             * Proxy and visibility checks ride here too: all three are
             * background chores, and `auto` balancing keeps a big check
             * batch from starving metrics.
             *
             * `checks` was missing, so CheckAccountVisibility jobs queued
             * and no worker was watching -- they sat pending forever while
             * the page said results would arrive shortly. A queue a job is
             * dispatched to and no supervisor reads is silence, not delay.
             */
            'queue' => ['metrics', 'default', 'proxies', 'checks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 2,
            'timeout' => 120,
            'nice' => 10,
        ],
    ],

    'environments' => [
        'production' => [
            'publish' => ['minProcesses' => 4, 'maxProcesses' => 40],
            'fanout' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'tokens' => ['minProcesses' => 2, 'maxProcesses' => 8],
            'metrics' => ['minProcesses' => 1, 'maxProcesses' => 6],
        ],

        'staging' => [
            'publish' => ['maxProcesses' => 10],
            'fanout' => ['maxProcesses' => 2],
            'tokens' => ['maxProcesses' => 2],
            'metrics' => ['maxProcesses' => 2],
        ],

        'local' => [
            'publish' => ['maxProcesses' => 3],
            'fanout' => ['maxProcesses' => 1],
            'tokens' => ['maxProcesses' => 1],
            'metrics' => ['maxProcesses' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];

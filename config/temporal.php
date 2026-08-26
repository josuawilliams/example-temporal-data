<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Temporal Server Address
    |--------------------------------------------------------------------------
    |
    | The host:port of the Temporal frontend service. Inside Docker this is
    | the service name from docker-compose.yml; from the host machine use
    | 127.0.0.1:7233.
    |
    */

    'address' => env('TEMPORAL_ADDRESS', 'temporal:7233'),

    /*
    |--------------------------------------------------------------------------
    | Task Queue
    |--------------------------------------------------------------------------
    |
    | The task queue that workflows are dispatched to. The worker started by
    | the temporal:run command polls this same queue.
    |
    */

    'task_queue' => env('TEMPORAL_TASK_QUEUE', 'default'),

];

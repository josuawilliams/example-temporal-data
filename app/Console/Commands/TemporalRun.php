<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Temporal\WorkerFactory;
use App\Temporal\Workflows\ExampleWorkflow;
use App\Temporal\Activities\ExampleActivity;

class TemporalRun extends Command
{
    protected $signature = 'temporal:run';

    protected $description = 'Start the Temporal worker';

    public function handle(): void
    {
        $taskQueue = config('temporal.task_queue');

        $factory = WorkerFactory::create();

        $worker = $factory->newWorker(taskQueue: $taskQueue);

        $worker->registerWorkflowTypes(ExampleWorkflow::class);
        $worker->registerActivityImplementations(new ExampleActivity());

        error_log('Temporal worker started on task queue: ' . $taskQueue);

        $factory->run();
    }
}

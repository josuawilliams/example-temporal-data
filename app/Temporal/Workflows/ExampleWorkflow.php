<?php

namespace App\Temporal\Workflows;

use Temporal\Activity\ActivityOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use App\Temporal\Activities\ExampleActivityInterface;
use Carbon\CarbonInterval;

#[WorkflowInterface]
class ExampleWorkflow
{
    private $activity;

    public function __construct()
    {
        $this->activity = Workflow::newActivityStub(
            ExampleActivityInterface::class,
            ActivityOptions::new()->withStartToCloseTimeout(CarbonInterval::minutes(2))
        );
    }

    #[WorkflowMethod]
    public function run(string $name)
    {
        $result = yield $this->activity->greet($name);

        return $result;
    }
}

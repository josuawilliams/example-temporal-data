<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use App\Temporal\Workflows\ExampleWorkflow;
use Ramsey\Uuid\Uuid;

class TemporalController extends Controller
{
    private function client(): WorkflowClient
    {
        $serviceClient = ServiceClient::create(env('TEMPORAL_ADDRESS', 'temporal:7233'));

        return WorkflowClient::create($serviceClient);
    }

    public function runExample(Request $request): JsonResponse
    {
        $name = $request->input('name', 'World');
        $workflowId = 'example-' . Uuid::uuid4()->toString();

        $workflow = $this->client()->newWorkflowStub(
            ExampleWorkflow::class,
            WorkflowOptions::new()
                ->withWorkflowId($workflowId)
                ->withTaskQueue(env('TEMPORAL_TASK_QUEUE', 'default'))
        );

        $this->client()->start($workflow, $name);

        return response()->json([
            'workflow_id' => $workflowId,
            'status' => 'started',
        ]);
    }

    public function getResult(string $workflowId): JsonResponse
    {
        $stub = $this->client()->newUntypedRunningWorkflowStub($workflowId);

        $result = $stub->getResult();

        return response()->json([
            'workflow_id' => $workflowId,
            'result' => $result,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use App\Temporal\Workflows\ExampleWorkflow;
use App\Temporal\Workflows\ReportWorkflow;
use App\Temporal\Workflows\RefreshReportViewsWorkflow;
use Ramsey\Uuid\Uuid;

class TemporalController extends Controller
{
    private ?WorkflowClient $client = null;

    private function client(): WorkflowClient
    {
        if ($this->client === null) {
            $serviceClient = ServiceClient::create(config('temporal.address'));

            $this->client = WorkflowClient::create($serviceClient);
        }

        return $this->client;
    }

    public function runExample(Request $request): JsonResponse
    {
        $name = $request->input('name', 'World');
        $workflowId = 'example-' . Uuid::uuid4()->toString();

        $client = $this->client();

        $workflow = $client->newWorkflowStub(
            ExampleWorkflow::class,
            WorkflowOptions::new()
                ->withWorkflowId($workflowId)
                ->withTaskQueue(config('temporal.task_queue'))
        );

        $client->start($workflow, $name);

        return response()->json([
            'workflow_id' => $workflowId,
            'status' => 'started',
        ]);
    }

    /**
     * Runs one workflow that queries sr_akta, sr_angsuran and sr_debit_note
     * in parallel, then waits for all three before responding.
     */
    public function report(Request $request): JsonResponse
    {
        // 'limit' now only affects total_matching (an approximate count via
        // ->count()); the row sample itself is capped by the activity at
        // MAX_SAMPLE_ROWS regardless of this value.
        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min($limit, 2_000_000));

        $client = $this->client();

        $workflow = $client->newWorkflowStub(
            ReportWorkflow::class,
            WorkflowOptions::new()
                ->withWorkflowId('report-' . Uuid::uuid4()->toString())
                ->withTaskQueue(config('temporal.task_queue'))
        );

        $startedAt = microtime(true);

        $result = $client->start($workflow, $limit)->getResult(timeout: 300);

        return response()->json([
            'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'data' => $result,
        ]);
    }

    /**
     * Refreshes mv_sr_akta, mv_sr_angsuran and mv_sr_debit_note in temp_db.
     * All three run in one Postgres transaction: if any REFRESH fails, none
     * of the views change. Rows never pass through PHP.
     */
    public function refreshReportViews(): JsonResponse
    {
        $client = $this->client();

        $workflow = $client->newWorkflowStub(
            RefreshReportViewsWorkflow::class,
            WorkflowOptions::new()
                ->withWorkflowId('refresh-views-' . Uuid::uuid4()->toString())
                ->withTaskQueue(config('temporal.task_queue'))
        );

        $startedAt = microtime(true);

        $result = $client->start($workflow)->getResult(timeout: 600);

        return response()->json([
            'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'per_view_ms' => $result,
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

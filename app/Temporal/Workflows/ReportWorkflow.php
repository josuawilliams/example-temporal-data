<?php

namespace App\Temporal\Workflows;

use App\Temporal\Activities\ReportActivityInterface;
use Carbon\CarbonInterval;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Promise;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
class ReportWorkflow
{
    private $activity;

    public function __construct()
    {
        $this->activity = Workflow::newActivityStub(
            ReportActivityInterface::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minutes(5))
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withMaximumAttempts(3)
                        ->withInitialInterval(CarbonInterval::seconds(1))
                )
        );
    }

    #[WorkflowMethod]
    public function run(int $limit = 100)
    {
        $startedAt = Workflow::now();

        // Called without yield, so each returns a promise and the activity is
        // dispatched immediately. Yielding one at a time here would serialise
        // them and defeat the point.
        $akta = $this->activity->fetchAkta($limit);
        $angsuran = $this->activity->fetchAngsuran($limit);
        $debitNote = $this->activity->fetchDebitNote($limit);

        // Single yield: the workflow resumes once all three have resolved.
        // Order of results matches the order of the promises, not completion.
        [$aktaResult, $angsuranResult, $debitNoteResult] = yield Promise::all([
            $akta,
            $angsuran,
            $debitNote,
        ]);

        return [
            'akta' => $aktaResult,
            'angsuran' => $angsuranResult,
            'debit_note' => $debitNoteResult,
            'elapsed_ms' => (int) round((Workflow::now()->getTimestamp() - $startedAt->getTimestamp()) * 1000),
        ];
    }
}

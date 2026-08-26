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
    public function run(int $limit)
    {
        $startedAt = Workflow::now();

        // Promise::all gagal cepat: begitu satu activity ditolak, seluruh
        // promise langsung ditolak walau dua lainnya sudah berhasil.
        // otherwise() mengubah penolakan jadi nilai normal, jadi satu
        // tabel gagal tidak membuang hasil dua lainnya.
        $toSafeResult = static fn (string $table) => static fn (\Throwable $e) => [
            'table' => $table,
            'error' => $e->getMessage(),
        ];

        $akta = $this->activity->fetchAkta($limit)->otherwise($toSafeResult('sr_akta'));
        $angsuran = $this->activity->fetchAngsuran($limit)->otherwise($toSafeResult('sr_angsuran'));
        $debitNote = $this->activity->fetchDebitNote($limit)->otherwise($toSafeResult('sr_debit_note'));

        [$aktaResult, $angsuranResult, $debitNoteResult] = yield Promise::all([
            $akta,
            $angsuran,
            $debitNote,
        ]);

        // Workflow::getLogger() sadar replay: diam saat history sedang
        // di-replay. Log::info() biasa akan terpicu berulang kali pada
        // setiap replay dan mencetak ulang angka yang sudah dilog.
        Workflow::getLogger()->info('report selesai diambil', [
            'akta' => $aktaResult['sample_rows'] ?? ('error: ' . ($aktaResult['error'] ?? 'unknown')),
            'angsuran' => $angsuranResult['sample_rows'] ?? ('error: ' . ($angsuranResult['error'] ?? 'unknown')),
            'debit_note' => $debitNoteResult['sample_rows'] ?? ('error: ' . ($debitNoteResult['error'] ?? 'unknown')),
        ]);

        return [
            'akta' => $aktaResult,
            'angsuran' => $angsuranResult,
            'debit_note' => $debitNoteResult,
            'elapsed_ms' => (int) round((Workflow::now()->getTimestamp() - $startedAt->getTimestamp()) * 1000),
        ];
    }
}

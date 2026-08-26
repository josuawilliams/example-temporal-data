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
class RefreshReportViewsWorkflow
{
    private $activity;

    public function __construct()
    {
        $this->activity = Workflow::newActivityStub(
            ReportActivityInterface::class,
            ActivityOptions::new()
                // sr_debit_note tanpa index; REFRESH CONCURRENTLY saja
                // butuh ~19-30 detik saat tes.
                ->withStartToCloseTimeout(CarbonInterval::minutes(10))
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withMaximumAttempts(2)
                        ->withInitialInterval(CarbonInterval::seconds(5))
                )
        );
    }

    #[WorkflowMethod]
    public function run()
    {
        $startedAt = Workflow::now();

        // otherwise() mengubah penolakan jadi nilai yang berhasil: satu
        // view gagal (misalnya timeout) tidak membuang dua lainnya yang
        // sudah ter-commit di koneksi masing-masing dan tidak bisa
        // di-rollback dari sini.
        $toSafeResult = static fn (string $view) => static fn (\Throwable $e) => [
            'view' => $view,
            'error' => $e->getMessage(),
        ];

        $akta = $this->activity->refreshAktaView()->otherwise($toSafeResult('mv_sr_akta'));
        $angsuran = $this->activity->refreshAngsuranView()->otherwise($toSafeResult('mv_sr_angsuran'));
        $debitNote = $this->activity->refreshDebitNoteView()->otherwise($toSafeResult('mv_sr_debit_note'));

        // Seorang promise di-resolve, baru workflow melanjutkan.
        // Urutan hasil cocok dengan urutan promise, bukan urutan selesai.
        [$aktaResult, $angsuranResult, $debitNoteResult] = yield Promise::all([
            $akta,
            $angsuran,
            $debitNote,
        ]);

        Workflow::getLogger()->info('materialized views selesai di-refresh', [
            'akta' => $aktaResult,
            'angsuran' => $angsuranResult,
            'debit_note' => $debitNoteResult,
        ]);

        return [
            'akta' => $aktaResult,
            'angsuran' => $angsuranResult,
            'debit_note' => $debitNoteResult,
            'elapsed_ms' => (int) round((Workflow::now()->getTimestamp() - $startedAt->getTimestamp()) * 1000),
        ];
    }
}

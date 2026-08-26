<?php

namespace App\Temporal\Activities;

use Illuminate\Support\Facades\DB;

class ReportActivity implements ReportActivityInterface
{
    /**
     * Temporal caps an activity result at ~2MB, so every query is bounded.
     * Only the requested columns are selected: the tables are wide and
     * SELECT * would drag unused columns across the wire.
     */
    public function fetchAkta(int $limit): array
    {
        return $this->query('sr_akta', ['no_akta', 'notaris'], $limit);
    }

    public function fetchAngsuran(int $limit): array
    {
        return $this->query('sr_angsuran', ['ppjb_id', 'kd_transaksi', 'kd_perusahaan'], $limit);
    }

    public function fetchDebitNote(int $limit): array
    {
        return $this->query('sr_debit_note', [
            'no_dn',
            'nama',
            'blok_no',
            'no_kuitansi',
            'no_faktur_pajak',
        ], $limit);
    }

    private function query(string $table, array $columns, int $limit): array
    {
        $startedAt = microtime(true);

        $rows = DB::connection('reporting')
            ->table($table)
            ->select($columns)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return [
            'table' => $table,
            'count' => count($rows),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'rows' => $rows,
        ];
    }
}

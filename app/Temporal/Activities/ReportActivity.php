<?php

namespace App\Temporal\Activities;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportActivity implements ReportActivityInterface
{
    // Temporal membatasi hasil activity sekitar 2MB, dan memory PHP worker
    // juga terbatas, jadi "rows" selalu berupa sampel yang dibatasi - bukan
    // seluruh hasil. Kalau pemanggil butuh semua baris, harus pakai request
    // terpisah dengan pagination/streaming, bukan lewat workflow history.
    private const MAX_SAMPLE_ROWS = 500;

    // Pemetaan nama view -> nama tabel sumber lewat FDW. Dipakai untuk
    // validasi jumlah baris setelah refresh.
    private const VIEW_SOURCE_MAP = [
        'mv_sr_akta'       => 'staging.sr_akta',
        'mv_sr_angsuran'   => 'staging.sr_angsuran',
        'mv_sr_debit_note' => 'staging.sr_debit_note',
    ];

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

    public function refreshAktaView(): array
    {
        return $this->refreshView('mv_sr_akta');
    }

    public function refreshAngsuranView(): array
    {
        return $this->refreshView('mv_sr_angsuran');
    }

    public function refreshDebitNoteView(): array
    {
        return $this->refreshView('mv_sr_debit_note');
    }

    /**
     * Melakukan refresh satu materialized view dalam satu transaksi sendiri
     * pada koneksi sendiri. Data baris tidak pernah melewati PHP: REFRESH
     * berjalan seluruhnya di dalam Postgres melalui postgres_fdw, kode ini
     * hanya mengirim perintah SQL.
     *
     * Tidak ada jaminan all-or-nothing lintas tiga view lagi: ketika
     * workflow menjalankan tiga activity ini secara paralel (satu koneksi
     * per activity), Postgres tidak bisa berbagi satu transaksi lintas
     * koneksi terpisah. Jika mv_sr_debit_note gagal setelah mv_sr_akta dan
     * mv_sr_angsuran sudah ter-commit, dua yang terakhir tetap ter-update.
     * Trade-off ini yang mempercepat proses secara paralel - Promise::all()
     * dengan otherwise() pada workflow menangani satu view gagal tanpa
     * membuang hasil dua lainnya.
     *
     * CONCURRENTLY hanya bisa dipakai jika view sudah terisi data minimal
     * sekali; Postgres menolaknya pada view kosong ("not populated").
     * Refresh pertama pada view baru menggunakan REFRESH biasa, setelah itu
     * baru CONCURRENTLY bisa dipakai.
     */
    private function refreshView(string $view): array
    {
        // Satu koneksi baru per pemanggilan: tiga activity ini berjalan
        // paralel dari tiga pemanggilan terpisah, dan koneksi Laravel tidak
        // aman digunakan bersamaan antar perintah concurrent.
        $connection = DB::connection('reporting_target');
        $startedAt = microtime(true);

        // Cek apakah view sudah terisi data (ispopulated) atau masih kosong
        $isPopulated = (bool) $connection
            ->table('pg_matviews')
            ->where('matviewname', $view)
            ->value('ispopulated');

        $sql = $isPopulated
            ? "REFRESH MATERIALIZED VIEW CONCURRENTLY {$view}"
            : "REFRESH MATERIALIZED VIEW {$view}";

        $connection->transaction(fn () => $connection->statement($sql));

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Validasi: hitung jumlah baris view dan bandingkan dengan tabel
        // sumber. Keduanya dijalankan pada koneksi yang sama (reporting_target)
        // sehingga konsisten satu sama lain, dan masing-masing menggunakan
        // snapshot Postgres tersendiri sehingga hasilnya akurat (tidak ada
        // ghost row MVCC).
        $sourceTable = self::VIEW_SOURCE_MAP[$view] ?? null;

        $viewCount = $sourceTable !== null
            ? $connection->table($view)->count()
            : null;

        $sourceCount = $sourceTable !== null
            ? $connection->table($sourceTable)->count()
            : null;

        $isConsistent = $viewCount !== null && $sourceCount !== null
            && $viewCount === $sourceCount;

        $result = [
            'view'          => $view,
            'duration_ms'   => $durationMs,
            'mode'          => $isPopulated ? 'concurrently' : 'initial',
            'source_rows'   => $sourceCount,
            'view_rows'     => $viewCount,
            'is_consistent' => $isConsistent,
        ];

        Log::channel('workflow')->info('materialized view refreshed + validated', $result);

        return $result;
    }

    private function query(string $table, array $columns, int $limit): array
    {
        $startedAt = microtime(true);

        $connection = DB::connection('reporting')->table($table);

        // Menghitung lebih dari 1 juta baris tanpa index adalah full scan
        // tersendiri; hanya dihitung jika pemanggil meminta lebih dari
        // batas sampel, supaya request kecil tetap ringan.
        $totalMatching = $limit > self::MAX_SAMPLE_ROWS
            ? $connection->count()
            : null;

        $sampleSize = min($limit, self::MAX_SAMPLE_ROWS);

        $rows = DB::connection('reporting')
            ->table($table)
            ->select($columns)
            ->limit($sampleSize)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::channel('workflow')->info('activity.fetch', [
            'table' => $table,
            'requested' => $limit,
            'sample_rows' => count($rows),
            'total_matching' => $totalMatching,
            'duration_ms' => $durationMs,
        ]);

        return [
            'table' => $table,
            'requested' => $limit,
            'sample_rows' => count($rows),
            'total_matching' => $totalMatching,
            'duration_ms' => $durationMs,
            'rows' => $rows,
        ];
    }
}

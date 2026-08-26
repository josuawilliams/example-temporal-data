# Dokumentasi API

## Tentang Proyek

Ini adalah aplikasi Laravel yang terintegrasi dengan **Temporal** — sebuah engine workflow yang menjalankan proses-proses berat (query database besar, refresh materialized view) secara paralel dan terdistribusi. Workflow dijalankan oleh worker terpisah (RoadRunner), bukan langsung oleh server HTTP Laravel.

**Arsitektur dasar:**

```
Client (Postman/curl)
    |
    v
Laravel (app container, port 8000)
    |  mengirim workflow ke
    v
Temporal Server (port 7233)
    |  mendispatch activity ke
    v
Worker (RoadRunner, membaca kode PHP yang sama)
    |  query/update database langsung
    v
Postgres (2 database berbeda)
    ├── dummy_db  (host Windows, PG17) → tabel sumber (sr_akta, sr_angsuran, sr_debit_note)
    └── temp_db   (host Windows, PG17) → materialized view (mv_sr_akta, mv_sr_angsuran, mv_sr_debit_note)
```

---

## Daftar Endpoint

| Metode | URL | Fungsi |
|--------|-----|--------|
| `GET` | `/api/temporal/report` | Ambil sampel data dari 3 tabel secara paralel |
| `POST` | `/api/temporal/report/refresh` | Refresh 3 materialized view, validasi jumlah baris |
| `POST` | `/api/temporal/example` | Jalankan workflow contoh sederhana |
| `GET` | `/api/temporal/example/{workflowId}` | Ambil hasil workflow contoh |
| `GET` | `/up` | Health check Laravel |

> **Catatan:** Semua endpoint (kecuali `/up`) menggunakan prefix `/api` secara otomatis karena didefinisikan di `routes/api.php`. Prefix ini diatur oleh Laravel 11+ di `bootstrap/app.php` melalui parameter `$apiPrefix` (default `'api'`). Lihat `vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:162`.

---

## 1. GET `/api/temporal/report`

### Apa yang dilakukan

Mengambil sampel data dari **3 tabel sekaligus secara paralel** di database sumber (`dummy_db`). Ketiga query dijalankan bersamaan melalui Temporal, bukan satu per satu.

### Cara kerja

1. Client mengirim request ke Laravel
2. Laravel membuat workflow `ReportWorkflow` dan mengirimkannya ke Temporal
3. Temporal mendispatch 3 activity ke worker secara bersamaan:
   - `Report.fetchAkta` → query tabel `sr_akta` di `dummy_db`
   - `Report.fetchAngsuran` → query tabel `sr_angsuran` di `dummy_db`
   - `Report.fetchDebitNote` → query tabel `sr_debit_note` di `dummy_db`
4. Worker menjalankan ketiga query dalam 3 proses PHP terpisah (paralel berkat `num_workers: 4` di `.rr.yaml`)
5. Hasil dikumpulkan oleh workflow melalui `Promise::all()`, dikirim balik ke Laravel
6. Laravel mengembalikan JSON ke client

### Parameter

| Parameter | Tipe | Default | Keterangan |
|-----------|------|---------|------------|
| `limit` | integer | 100 | Jumlah baris yang diminta. Maksimum 2.000.000. |

> `limit` hanya mempengaruhi `total_matching` (hitung approximate via `->count()`). Jumlah baris sampel yang dikembalikan selalu dibatasi maksimum 500 baris oleh activity (`MAX_SAMPLE_ROWS`), berapa pun angka `limit` yang dikirim. Ini karena Temporal membatasi hasil activity sekitar 2MB.

### Contoh request

```
GET http://localhost:8000/api/temporal/report?limit=500
```

### Contoh response

```json
{
    "took_ms": 2500,
    "data": {
        "akta": {
            "table": "sr_akta",
            "requested": 500,
            "sample_rows": 500,
            "total_matching": 52221,
            "duration_ms": 220,
            "rows": [
                {"no_akta": "355/IV/K.G/93.", "notaris": "JOHN LEONARD WAWORUNTU"}
            ]
        },
        "angsuran": {
            "table": "sr_angsuran",
            "requested": 500,
            "sample_rows": 500,
            "total_matching": 493071,
            "duration_ms": 240,
            "rows": [
                {"ppjb_id": "4218", "kd_transaksi": "ANG  ", "kd_perusahaan": "SKLG "}
            ]
        },
        "debit_note": {
            "table": "sr_debit_note",
            "requested": 500,
            "sample_rows": 500,
            "total_matching": 1087752,
            "duration_ms": 350,
            "rows": [
                {"no_dn": "201511001", "nama": "UMUM", "blok_no": "-", "no_kuitansi": null, "no_faktur_pajak": null}
            ]
        },
        "elapsed_ms": 2100
    }
}
```

### Penjelasan field response

| Field | Keterangan |
|-------|------------|
| `took_ms` | Total waktu dari client mengirim request sampai menerima response (milidetik) |
| `elapsed_ms` | Waktu total workflow berjalan di Temporal |
| `sample_rows` | Jumlah baris sampel yang benar-benar dikembalikan (maks 500) |
| `total_matching` | Total baris di tabel sumber (hanya dihitung jika `limit` > 500) |
| `duration_ms` | Waktu eksekusi query untuk tabel tersebut |
| `rows` | Array berisi baris-baris sampel |

### Bagaimana paralel bekerja

Di `ReportWorkflow.php`, ketiga activity dipanggil tanpa `yield` terlebih dahulu:

```php
$akta = $this->activity->fetchAkta($limit);       // mengembalikan promise, belum dieksekusi
$angsuran = $this->activity->fetchAngsuran($limit);
$debitNote = $this->activity->fetchDebitNote($limit);

// yield Promise::all() menunggu ketiganya selesai
[$a, $b, $c] = yield Promise::all([$akta, $angsuran, $debitNote]);
```

Tanpa `Promise::all`, ketiga promise harus di-yield satu per satu → berurutan (sekitar 25 detik total). Dengan `Promise::all`, mereka dijalankan bersamaan → sekitar 3-5 detik (dibatasi oleh tabel terberat: `sr_debit_note`).

`otherwise()` menangani kegagalan satu activity tanpa menjatuhkan dua lainnya — jika `sr_debit_note` gagal (misal timeout), hasil `sr_akta` dan `sr_angsuran` tetap dikembalikan dengan field `error`.

---

## 2. POST `/api/temporal/report/refresh`

### Apa yang dilakukan

Melakukan **refresh 3 materialized view** di database tujuan (`temp_db`). Setiap view di-refresh secara paralel masing-masing dalam transaksi sendiri, lalu hasilnya divalidasi dengan menghitung jumlah baris view dan membandingkannya dengan jumlah baris tabel sumber.

### Mengapa materialized view?

Materialized view adalah tabel hasil yang disimpan secara fisik di database. Berbeda dengan view biasa (yang dihitung ulang setiap kali di-query), materialized view menyimpan hasilnya sehingga query terhadap view ini sangat cepat. Namun, isinya tidak otomatis ter-update — harus di-refresh secara manual atau terjadwal.

Di proyek ini:
- **Database sumber:** `dummy_db` → tabel `sr_akta` (52.221 baris), `sr_angsuran` (493.071 baris), `sr_debit_note` (1.087.752 baris)
- **Database tujuan:** `temp_db` → materialized view `mv_sr_akta`, `mv_sr_angsuran`, `mv_sr_debit_note`

Koneksi antar database dilakukan melalui **postgres_fdw** (Foreign Data Wrapper) — Postgres bisa melihat tabel di database lain seolah-olah tabel itu lokal, tanpa data harus melewati PHP.

### Cara kerja

1. Client mengirim POST request
2. Laravel membuat workflow `RefreshReportViewsWorkflow`
3. Temporal mendispatch 3 activity ke worker secara bersamaan:
   - `Report.refreshAktaView` → refresh `mv_sr_akta` + validasi jumlah baris
   - `Report.refreshAngsuranView` → refresh `mv_sr_angsuran` + validasi jumlah baris
   - `Report.refreshDebitNoteView` → refresh `mv_sr_debit_note` + validasi jumlah baris
4. Tiap activity menjalankan transaksi sendiri di `temp_db`:
   - Cek apakah view sudah terisi data (via `pg_matviews.ispopulated`)
   - Jika sudah → pakai `REFRESH MATERIALIZED VIEW CONCURRENTLY` (tidak mengunci pembaca)
   - Jika kosong → pakai `REFRESH MATERIALIZED VIEW` biasa (mengunci view selama proses)
   - Hitung jumlah baris view → hitung jumlah baris tabel sumber → bandingkan
5. Hasil validasi dikembalikan ke client

### Catatan penting: CONCURRENTLY vs biasa

| Mode | Kapan dipakai | Efek terhadap pembaca |
|------|--------------|----------------------|
| `initial` (REFRESH biasa) | View kosong / belum pernah diisi | View **tidak bisa dibaca** selama proses (ACCESS EXCLUSIVE lock) |
| `concurrently` | View sudah terisi minimal sekali | View **tetap bisa dibaca** selama proses |

Postgres menolak `CONCURRENTLY` pada view yang belum terisi (`ispopulated = false`). Kode secara otomatis mendeteksi kondisi ini dan memilih mode yang tepat.

### Contoh request

```
POST http://localhost:8000/api/temporal/report/refresh
```

### Contoh response

```json
{
    "took_ms": 25000,
    "per_view_ms": {
        "akta": {
            "view": "mv_sr_akta",
            "duration_ms": 511,
            "mode": "concurrently",
            "source_rows": 52221,
            "view_rows": 52221,
            "is_consistent": true
        },
        "angsuran": {
            "view": "mv_sr_angsuran",
            "duration_ms": 8721,
            "mode": "concurrently",
            "source_rows": 493071,
            "view_rows": 493071,
            "is_consistent": true
        },
        "debit_note": {
            "view": "mv_sr_debit_note",
            "duration_ms": 19093,
            "mode": "concurrently",
            "source_rows": 1087752,
            "view_rows": 1087752,
            "is_consistent": true
        }
    }
}
```

### Penjelasan field response

| Field | Keterangan |
|-------|------------|
| `took_ms` | Total waktu dari client mengirim request sampai menerima response |
| `duration_ms` | Waktu refresh untuk view tersebut |
| `mode` | `initial` = refresh pertama (view kosong); `concurrently` = refresh berikutnya (view sudah terisi) |
| `source_rows` | Jumlah baris tabel sumber (di `dummy_db` lewat FDW) |
| `view_rows` | Jumlah baris materialized view (di `temp_db`) |
| `is_consistent` | `true` jika jumlah baris view == jumlah baris sumber |

### Mengapa validasi penting?

Tanpa validasi, refresh bisa gagal diam-diam (misal karena koneksi FDW terputus di tengah proses) dan view berisi data lama atau kosong tanpa ada yang tahu. Dengan validasi, client langsung mengetahui apakah jumlah baris di view cocok dengan sumbernya.

### Mengapa transaksi dipisah per view?

Tiga activity dijalankan paralel masing-masing di koneksi terpisah. Postgres tidak bisa berbagi satu transaksi lintas koneksi berbeda. Artinya:
- **Kelebihan:** Refresh lebih cepat (~20 detik paralel vs ~50 detik berurutan)
- **Kekurangan:** Jika `mv_sr_debit_note` gagal setelah `mv_sr_akta` dan `mv_sr_angsuran` sudah ter-commit, dua yang terakhir tetap ter-update (tidak bisa di-rollback dari sini)

`otherwise()` di `RefreshReportViewsWorkflow` menangani kegagalan satu view tanpa menjatuhkan dua lainnya.

---

## 3. POST `/api/temporal/example`

### Apa yang dilakukan

Workflow contoh sederhana yang menjalankan satu activity (`Example.greet`) — mengembalikan string "Hello, {name}! Processed at {timestamp}".

### Contoh request

```
POST http://localhost:8000/api/temporal/example
Content-Type: application/json

{"name": "Joe"}
```

### Contoh response

```json
{
    "workflow_id": "example-9eedfc8d-b0a0-4f09-9899-9f89f188910e",
    "status": "started"
}
```

> Response ini hanya mengonfirmasi workflow dimulai, bukan hasilnya. Untuk mengambil hasil, gunakan endpoint berikutnya dengan `workflow_id` dari response ini.

---

## 4. GET `/api/temporal/example/{workflowId}`

### Apa yang dilakukan

Mengambil hasil dari workflow example yang sudah dijalankan sebelumnya.

### Contoh request

```
GET http://localhost:8000/api/temporal/example/example-9eedfc8d-b0a0-4f09-9899-9f89f188910e
```

### Contoh response

```json
{
    "workflow_id": "example-9eedfc8d-b0a0-4f09-9899-9f89f188910e",
    "result": "Hello, Joe! Processed at 2026-08-26 09:05:00"
}
```

---

## Struktur File

```
app/
├── Console/Commands/
│   └── TemporalRun.php          # Perintah artisan: temporal:run → menjalankan worker
├── Http/Controllers/
│   └── TemporalController.php   # Semua endpoint API
├── Temporal/
│   ├── Activities/
│   │   ├── ReportActivityInterface.php  # Interface untuk activity (patokan input/output)
│   │   ├── ReportActivity.php           # Implementasi: query 3 tabel + refresh 3 view
│   │   ├── ExampleActivityInterface.php # Interface activity contoh
│   │   └── ExampleActivity.php          # Implementasi activity contoh
│   └── Workflows/
│       ├── ReportWorkflow.php              # Query 3 tabel paralel → return sampel data
│       ├── RefreshReportViewsWorkflow.php  # Refresh 3 materialized view paralel + validasi
│       └── ExampleWorkflow.php             # Workflow contoh
config/
├── app.php          # timezone: Asia/Jakarta
├── database.php     # 3 koneksi: default (app), reporting (sumber), reporting_target (tujuan)
├── logging.php      # Channel 'workflow' → storage/logs/workflow-YYYY-MM-DD.log
└── temporal.php     # Alamat Temporal server + task queue
routes/
└── api.php          # Semua route endpoint
.rr.yaml            # Konfigurasi RoadRunner (worker pool, alamat Temporal)
```

---

## Koneksi Database

| Nama di config | Database | Isi | Akses dari container |
|----------------|----------|-----|---------------------|
| `default` | `dummy_db` di container `postgres` (PG15) | Tabel bawaan Laravel (sessions, cache, jobs) | `postgres:5432` |
| `reporting` | `dummy_db` di host Windows (PG17) | Tabel `sr_*` (~380 tabel, termasuk sr_akta, sr_angsuran, sr_debit_note) | `host.docker.internal:5432` |
| `reporting_target` | `temp_db` di host Windows (PG17) | Materialized view `mv_sr_*` + foreign table di schema `staging` | `host.docker.internal:5432` |

> **Catatan:** Ada dua Postgres berbeda dengan nama database `dummy_db` yang sama. Yang di container (PG15) cuma berisi tabel bawaan Laravel. Yang di host Windows (PG17) berisi tabel-tabel `sr_*` asli. Koneksi `reporting` menunjuk ke yang di host.

---

## Worker dan Paralelisme

Worker menggunakan **RoadRunner** (`.rr.yaml`), bukan `php artisan serve`. Pool worker dikonfigurasi dengan `num_workers: 4` untuk mengizinkan 4 activity berjalan bersamaan. Tanpa ini, `Promise::all` tetap menjalankan activity satu per satu meskipun kodenya sudah paralel.

| Service | Port | Fungsi |
|---------|------|--------|
| `app` | 8000 | Server HTTP Laravel |
| `worker` | - | RoadRunner worker (menjalankan activity/workflow) |
| `temporal` | 7233 | Temporal server (gRPC) |
| `temporal-ui` | 8081 | Dashboard Temporal (web) |
| `postgres` | 5432 | Database aplikasi (container) |
| `temporal-postgres` | - | Database internal Temporal |

---

## Cara Restart

Ubah kode PHP (activity, workflow, controller, config) →
```sh
docker compose restart worker
```

Ubah `.env` →
```sh
docker compose up -d --force-recreate app worker
```

Ubah `Dockerfile` atau `.rr.yaml` →
```sh
docker compose build app worker && docker compose up -d app worker
```

---

## Log Workflow

Log workflow disimpan di `storage/logs/workflow-YYYY-MM-DD.log` (driver `daily`, otomatis rotasi 14 hari).

Lihat log secara real-time:
```sh
docker compose exec app tail -f storage/logs/workflow-$(date +%Y-%m-%d).log
```

Cari log refresh spesifik:
```sh
docker compose exec app grep "materialized view" storage/logs/workflow-$(date +%Y-%m-%d).log | tail -5
```

---

## Catatan Teknis Penting

### Temporal membatasi payload ~2MB

Hasil activity tidak boleh lebih dari ~2MB. Untuk tabel besar (sr_debit_note: 1 juta baris), mengembalikan seluruh baris lewat workflow result akan gagal. Itulah kenapa endpoint `/report` hanya mengembalikan **sampel** (maks 500 baris), bukan seluruh data.

### Memory limit PHP

Worker menggunakan `memory_limit=512M` (dinaikkan dari default 128M). Tanpa ini, query besar yang menghidrasi banyak baris ke memori akan mematikan proses PHP secara diam-diam (fatal error tanpa pesan yang jelas — hanya terlihat sebagai "worker hung or killed" di log RoadRunner).

### sr_debit_note tanpa index

Tabel `sr_debit_note` (1.087.752 baris) **tidak memiliki index sama sekali**. Setiap query ke tabel ini selalu melakukan full table scan. Kolom `no_dn` juga tidak unik (1.087.539 baris tapi hanya 69.702 nilai berbeda). Ini menjadikan tabel ini yang paling lambat di semua operasi.

### unique index untuk CONCURRENTLY

Postgres mensyaratkan unique index pada materialized view agar bisa menggunakan `REFRESH ... CONCURRENTLY`. Di `sr_akta` dan `sr_debit_note`, tidak ada kolom yang unik secara alami, sehingga digunakan `row_number() OVER (...)` sebagai surrogate key:

```sql
-- sr_akta: akta_id tidak unik (52.221 baris, hanya 17.407 nilai beda)
CREATE MATERIALIZED VIEW mv_sr_akta AS
SELECT row_number() OVER (ORDER BY akta_id, ppjb_id, no_akta) AS row_id,
       akta_id, ppjb_id, no_akta, notaris
FROM staging.sr_akta;

CREATE UNIQUE INDEX idx_mv_sr_akta_id ON mv_sr_akta (row_id);
```

### Waktu refresh (data dari pengujian)

| View | Baris | Refresh pertama (initial) | Refresh berikutnya (concurrently) |
|------|-------|--------------------------|----------------------------------|
| mv_sr_akta | 52.221 | ~1.6 detik | ~0.5 detik |
| mv_sr_angsuran | 493.071 | ~16 detik | ~8.7 detik |
| mv_sr_debit_note | 1.087.752 | ~30 detik | ~19 detik |
| **Total (paralel)** | | ~30 detik | ~19 detik |

Paralel: waktu = lama view terberat (bukan jumlah). Berurutan: waktu = jumlah ketiganya (~50 detik).

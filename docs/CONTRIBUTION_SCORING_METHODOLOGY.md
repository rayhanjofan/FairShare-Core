# METODOLOGI PERHITUNGAN KONTRIBUSI, FILTERED DELTA & WEIGHTED EFFORT INDEX (WEI)
## FairShare — Collaboration Intelligence & Transparency Engine

Dokumen ini mendefinisikan secara resmi metodologi, landasan matematis, aturan validasi, dan batasan operasional dari **Mesin Kontribusi FairShare (FairShare Contribution Engine)**.

---

## 1. Definisi Raw Delta

**Raw Delta** adalah nilai aktivitas mentah (*Detected Activity Event*) yang dikumpulkan dari sumber eksternal (Google Drive Activity API atau GitHub API) sebelum proses verifikasi dan validasi kelayakan akademis.

### Ketentuan Raw Delta:
1. **Satuan Dasar:** Satu action `edit` unik yang teramati dan berhasil dikumpulkan bernilai **1.00 Raw Delta**.
2. Satu action `create` unik yang teramati dan berhasil dikumpulkan bernilai **1.00 Raw Delta**.
3. **Idempotency:** Aktivitas yang sama tidak pernah dicatat berulang kali karena dilindungi oleh SHA-256 event external ID.
4. **Bukan Waktu Kerja atau Volume Ketikan:** Raw Delta **tidak boleh** diasumsikan sebagai durasi kerja jam manusia, jumlah kata, atau jumlah karakter yang diketik.
5. **Terminologi Resmi:** Digunakan istilah **Detected Activity Event**, bukan *Jumlah Ketikan*.
6. Raw Delta mencerminkan jejak digital mentah apa adanya dari penyedia layanan pihak ketiga.

---

## 2. Definisi Filtered Delta

**Filtered Delta** adalah nilai aktivitas yang telah lolos pengujian terhadap seluruh aturan validasi integritas FairShare (*FairShare Multi-Tier Validation Rules*).

### Ketentuan Filtered Delta:
- Jika suatu event memenuhi **seluruh 14 kriteria validasi**:
  - `raw_delta = 1.00`
  - `filtered_delta = 1.00`
  - `validation_status = 'valid'`
  - `validation_reason = null`
- Jika suatu event tidak memenuhi salah satu kriteria validasi:
  - `raw_delta = 1.00` (atau nilai mentah yang teramati)
  - `filtered_delta = 0.00`
  - `validation_status = 'rejected' | 'pending' | 'unattributed' | 'duplicate'`
  - `validation_reason` diisi dengan kode alasan spesifik yang dapat diaudit.

---

## 3. Kriteria Validasi Event (14 Kriteria)

Untuk memastikan bahwa aktivitas kolaboratif yang diperhitungkan adalah sah dan dapat dipertanggungjawabkan, sistem menerapkan 14 kriteria wajib:

1. **Integrated External Source:** Event berasal dari penyedia resmi (`gdocs` via Google Drive Activity API atau `github` via GitHub Events/Commits API).
2. **Valid Task Linkage:** Event terhubung dengan `task_id` yang valid (UUID v4) dan terdaftar di database FairShare.
3. **Task Type Match:** Tipe tugas pada database cocok dengan sumber event (misal: `task_type = 'gdocs'`).
4. **Active Team Entity:** Tugas terhubung dengan kelompok bertipe tim kerja (`group_type = 'team'`).
5. **Valid Parent Class:** Kelompok tim bernaung di bawah kelas perkuliahan resmi (`parent_group_id` merujuk ke grup bertipe `'class'`).
6. **Enrolled Group Member:** Pengguna tercatat sah sebagai mahasiswa anggota kelompok bersangkutan pada tabel `group_members`.
7. **Verified Actor Identity:** Identitas pelaku aktivitas terverifikasi melalui mekanisme OAuth 2.0 yang terhubung.
8. **Current User Ownership (`isCurrentUser`):** Untuk Google Docs, flag `isCurrentUser = true` harus terpenuhi saat sinkronisasi ditarik menggunakan sesi akun Google milik mahasiswa bersangkutan.
9. **Permitted Action Types:** Aksi aktivitas merupakan modifikasi konten substantive (`edit` atau `create`). Aksi administratif dokumen (misal: rename, move, delete) tidak dihitung sebagai kontribusi pengerjaan konten.
10. **Timestamp Integrity:** Timestamp aktivitas valid, tidak kosong, memiliki format waktu yang dapat diparse, dan tidak mendahului waktu server sistem saat ini (melebihi toleransi clock-drift 15 menit).
11. **Document Matching:** Resource ID dokumen yang diedit (`document_id`) cocok persis dengan `external_resource_id` Google Docs yang ditautkan ke tugas.
12. **External ID Presence:** Memiliki pengenal unik aktivitas (`external_id`) yang valid.
13. **Non-Duplicate Event:** Event belum pernah diproses sebelumnya (terjaga via unique constraint database).
14. **Production Mode:** Event bukan merupakan catatan uji coba manual (`entry_mode != 'manual_test'`).

---

## 4. Alasan Event Ditolak (*Validation Reasons*)

Setiap event yang menghasilkan `filtered_delta = 0.00` menyimpan alasan penolakan pada kolom metadata JSONB:

| Kode Alasan (`validation_reason`) | Status Validasi | Penjelasan Audit |
|---|---|---|
| `actor_not_verified` | `rejected` / `unattributed` | Identitas aktor tidak dapat dikonfirmasi dengan akun OAuth mahasiswa terhubung. |
| `user_not_group_member` | `rejected` | Pelaku bukan mahasiswa anggota kelompok pemilik tugas. |
| `invalid_task` | `rejected` | Task ID tidak valid, berformat salah, atau tidak ditemukan. |
| `invalid_group` | `rejected` | Kelompok yang diasosiasikan tidak cocok dengan tugas. |
| `invalid_group_type` | `rejected` | Tugas tidak bernaung di kelompok bertipe tim. |
| `invalid_parent_class` | `rejected` | Kelompok belum terhubung ke kelas yang sah. |
| `invalid_action` | `rejected` | Aksi bukan edit atau create dokumen. |
| `invalid_timestamp` | `rejected` | Format tanggal/waktu aktivitas korup atau tidak terbaca. |
| `missing_timestamp` | `rejected` | Event tidak memiliki informasi waktu pengerjaan. |
| `future_timestamp` | `rejected` | Waktu aktivitas mendahului waktu sistem saat ini. |
| `wrong_document` | `rejected` | File ID Google Docs berbeda dengan dokumen resmi tugas. |
| `task_missing_resource` | `rejected` | Tugas belum ditautkan dengan URL resource dokumen resmi. |
| `task_type_mismatch` | `rejected` | Tipe tugas tidak sesuai dengan sumber event. |
| `duplicate_event` | `duplicate` | Event telah dicatat sebelumnya dalam basis data. |
| `manual_test` | `rejected` | Entri uji coba manual yang tidak masuk penilaian resmi. |
| `missing_external_id` | `rejected` | External ID tidak disertakan. |
| `missing_task_context` | `pending` | Konteks relasi tugas belum dapat dipastikan. |

---

## 5. Mekanisme Idempotency

FairShare menerapkan mekanisme deterministik SHA-256 untuk memastikan bahwa sinkronisasi berkali-kali tidak akan menimbulkan duplikasi delta:

```text
external_id = SHA-256( canonical_json({
    task_id,
    member_id,
    file_id,
    activity,
    action,
    action_index
}))
```

Basis data PostgreSQL menerapkan partial unique index:
```sql
CREATE UNIQUE INDEX uq_telemetry_task_source_external
ON public.telemetry_logs (task_id, source, external_id)
WHERE task_id IS NOT NULL AND external_id IS NOT NULL;
```

Ketika sinkronisasi dijalankan berulang kali oleh mahasiswa atau cron worker, query INSERT menggunakan:
```sql
ON CONFLICT (task_id, source, external_id) DO UPDATE SET ...
```
sehingga record lama diperbarui tanpa pernah menambah baris baru ataupun menggandakan skor.

---

## 6. Rumus Weighted Effort Index (WEI)

Weighted Effort Index (WEI) dirancang sebagai representasi **proporsi kontribusi relatif** setiap anggota terhadap total aktivitas valid yang berhasil dihimpun kelompok, dengan mempertimbangkan bobot (*weight*) masing-masing tugas WBS.

### Langkah A — Skor Dasar Anggota pada Tugas ($t$)
Untuk setiap anggota $i$ dan tugas $t$:
$$MemberTaskScore(i, t) = \sum_{e \in ValidEvents(i, t)} filtered\_delta(e)$$

Hanya event dengan status `valid` (dan `filtered_delta > 0`) yang dijumlahkan.

### Langkah B — Total Skor Valid Tugas ($t$)
$$TotalValidScore_t = \sum_{m \in GroupMembers} MemberTaskScore(m, t)$$

### Langkah C — Kontribusi Relatif Anggota pada Satu Tugas ($WEI_{i, t}$)
Jika $TotalValidScore_t > 0$:
$$WEI_{i, t} = \left(\frac{MemberTaskScore(i, t)}{TotalValidScore_t}\right) \times 100\%$$

Jika $TotalValidScore_t = 0$:
Tugas tersebut belum memiliki aktivitas valid. Tugas ini dikecualikan dari penilaian proporsional aktif ($WEI_{i, t} = 0\%$).

### Langkah D — Normalisasi WEI Keseluruhan Kelompok ($WEI_i$)
Menggunakan bobot tugas $Weight_t \in [1, 5]$ dari tabel `public.tasks`:
$$WEI_i = \frac{\sum_{t \in EvaluatedTasks} \left(Weight_t \times WEI_{i, t}\right)}{\sum_{t \in EvaluatedTasks} Weight_t}$$

Dimana $EvaluatedTasks$ adalah himpunan seluruh tugas kelompok yang **memiliki aktivitas valid** ($TotalValidScore_t > 0$) dan berbobot positif ($Weight_t > 0$).

---

## 7. Contoh Perhitungan Manual (Skenario Verifikasi Bagian 7.D)

Diberikan kelompok dengan 4 orang mahasiswa: **A, B, C, D**.

Kelompok memiliki 2 tugas:
- **Tugas 1:** Bobot $W_1 = 2$.
  - A menghasilkan 10 Filtered Delta.
  - B menghasilkan 5 Filtered Delta.
  - C menghasilkan 0 Filtered Delta.
  - D menghasilkan 0 Filtered Delta.
  - $TotalValidScore_1 = 10 + 5 + 0 + 0 = 15$.

- **Tugas 2:** Bobot $W_2 = 1$.
  - A menghasilkan 0 Filtered Delta.
  - B menghasilkan 0 Filtered Delta.
  - C menghasilkan 10 Filtered Delta.
  - D menghasilkan 0 Filtered Delta.
  - $TotalValidScore_2 = 0 + 0 + 10 + 0 = 10$.

### Kalkulasi Per Tugas:
- **Tugas 1:**
  - $WEI_{A, 1} = (10 / 15) \times 100\% = 66.6667\%$
  - $WEI_{B, 1} = (5 / 15) \times 100\% = 33.3333\%$
  - $WEI_{C, 1} = (0 / 15) \times 100\% = 0.0000\%$
  - $WEI_{D, 1} = (0 / 15) \times 100\% = 0.0000\%$

- **Tugas 2:**
  - $WEI_{A, 2} = (0 / 10) \times 100\% = 0.0000\%$
  - $WEI_{B, 2} = (0 / 10) \times 100\% = 0.0000\%$
  - $WEI_{C, 2} = (10 / 10) \times 100\% = 100.0000\%$
  - $WEI_{D, 2} = (0 / 10) \times 100\% = 0.0000\%$

### Kalkulasi WEI Keseluruhan:
Penyebut bobot tugas: $\sum Weight_t = 2 + 1 = 3$.

- **Mahasiswa A:**
  $$WEI_A = \frac{(2 \times 66.6667\%) + (1 \times 0.0000\%)}{3} = \frac{133.3333\%}{3} = \mathbf{44.4444\%}$$

- **Mahasiswa B:**
  $$WEI_B = \frac{(2 \times 33.3333\%) + (1 \times 0.0000\%)}{3} = \frac{66.6667\%}{3} = \mathbf{22.2222\%}$$

- **Mahasiswa C:**
  $$WEI_C = \frac{(2 \times 0.0000\%) + (1 \times 100.0000\%)}{3} = \frac{100.0000\%}{3} = \mathbf{33.3333\%}$$

- **Mahasiswa D:**
  $$WEI_D = \frac{(2 \times 0.0000\%) + (1 \times 0.0000\%)}{3} = \frac{0.0000\%}{3} = \mathbf{0.0000\%}$$

### Total Seluruh Anggota:
$$44.4444\% + 22.2222\% + 33.3333\% + 0.0000\% = \mathbf{100.0000\%}$$

Hasil perhitungan di atas diverifikasi secara otomatis melalui unit test di `tests/WeiContributionEngineTest.php`.

---

## 8. Penanganan Anggota Tanpa Aktivitas

- Mahasiswa yang terdaftar sah di kelompok tetapi belum memiliki aktivitas valid pada tugas tetap diikutsertakan dalam struktur denominator kelompok.
- Mahasiswa tersebut memperoleh $WEI_{i, t} = 0\%$ pada tugas bersangkutan.
- Jika kelompok telah memiliki tugas lain yang memiliki data valid, maka mahasiswa pasif tersebut memperoleh $WEI_i = 0.0\%$.
- Sistem **tidak pernah menghapus** anggota pasif dari daftar tim agar transparansi kontribusi tetap terjaga bagi dosen pembimbing.

---

## 9. Penanganan Tugas Tanpa Data Valid

- Jika suatu tugas belum memiliki aktivitas tersaring ($TotalValidScore_t = 0$), maka tugas tersebut **tidak dimasukkan** ke dalam penyebut $\sum Weight_t$ pada kalkulasi WEI keseluruhan sementara.
- Jika **seluruh tugas** dalam kelompok belum memiliki aktivitas valid sama sekali ($\sum Weight_t = 0$):
  - Sistem tidak melakukan pembagian dengan nol.
  - Kelompok diberi status `insufficient_data` (**"Belum cukup data"**).
  - Skor anggota ditampilkan sebagai `Belum cukup data`, bukan rekayasa angka $0\%$ semu.
- Tampilan UI memisahkan secara transparan jumlah:
  - Tugas yang sudah memiliki data valid.
  - Tugas yang belum memiliki data aktivitas.
  - Tugas manual tanpa integrasi API.

---

## 10. Keterbatasan Google Drive Activity API

Pihak pengembang dan dosen pengampu wajib memahami batasan teknis dari Google Drive Activity API:
1. **Granularitas Batching:** Google Drive Activity API menggabungkan beberapa pengetikan berdekatan ke dalam satu objek event `edit`. Oleh karena itu, satu event edit merepresentasikan satu *sesi modifikasi dokumen*, bukan satu karakter atau satu kata.
2. **Atribusi Multi-Aktor:** Jika beberapa orang mengedit dokumen secara simultan pada milidetik yang sama, API Google Drive terkadang menyatukan aktivitas tersebut ke dalam satu DriveActivity bersama. FairShare hanya mengatribusikan aksi kepada pengguna jika `isCurrentUser = true` dan konteks aktor tunggal dapat dipastikan.
3. **Waktu Propagasi:** Log aktivitas Google Drive Activity API umumnya tersedia dalam rentang waktu beberapa detik hingga 2 menit setelah perubahan disimpan di server Google Docs.
4. **Izin Dokumen:** Aktivitas hanya dapat ditarik jika email akun Google mahasiswa yang terhubung memiliki izin akses (*Share*) minimal viewer/editor pada dokumen tersebut.

---

## 11. Batasan Keadilan dan Integritas Akademik

1. **WEI adalah Indikator Aktivitas, Bukan Kualitas:**
   FairShare secara tegas menyatakan bahwa WEI mengukur **proporsi jejak aktivitas kolaboratif**, bukan kedalaman pemikiran, kebenaran isi dokumen, atau nilai akhir mata kuliah.
2. **Ketiadaan Manipulasi Frekuensi:**
   Sistem tidak memberikan bonus bagi mahasiswa yang melakukan banyak klik edit berulang dalam waktu singkat.
3. **Kewenangan Penuh Dosen:**
   Hasil WEI berfungsi sebagai instrumen bantuan transparansi (*audit decision support tool*) bagi dosen untuk mengidentifikasi indikasi *free-rider* atau ketimpangan kerja tim, sementara evaluasi mutu konten tetap berada di tangan dosen pengampu.

---

## 12. Kesiapan Arsitektur untuk Integrasi GitHub

Arsitektur mesin kontribusi ini (`includes/telemetry_validator.php` dan `includes/contribution_engine.php`) dirancang sepenuhnya agnostik terhadap penyedia sumber:
- Antarmuka validator menerima parameter umum: `source`, `action_type`, `activity_timestamp`, `external_id`, dan metadata.
- Ketika integrasi GitHub diaktifkan pada iterasi berikutnya:
  - Commit GitHub unik dapat divalidasi dengan aturan yang setara (`action_type = 'commit'`, branch check, author matching).
  - Event commit yang valid akan menghasilkan `filtered_delta = 1.00`.
  - Mesin WEI secara otomatis menggabungkan seluruh tugas GitHub dan Google Docs menggunakan rumus berbobot yang sama tanpa memerlukan perubahan skema database.

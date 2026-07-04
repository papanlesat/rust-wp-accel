# [Goal Description]

Proyek ini akan memasuki **Fase 3** untuk mengimplementasikan Poin 4 (*Zero-Copy Deserialization*) dan Poin 5 (*Concurrency & AOT Caching*) dari arsitektur ekosistem PHP-Rust. Karena implementasi penuh memerlukan modifikasi sistem _database_ WordPress secara ekstrem, kita akan membangun *Proof of Concept* (PoC) yang solid dan terukur untuk membuktikan kelayakan kedua poin tersebut.

## User Review Required

> [!WARNING]
> **Skop Pembuktian (PoC)**
> - **Point 4 (Zero-Copy):** Alih-alih *FlatBuffers*, saya merekomendasikan pustaka **`rkyv`** (standar de-facto zero-copy di Rust saat ini). Kita akan membuat fungsi yang bisa membaca berkas biner `rkyv` langsung ke memori PHP.
> - **Point 5 (Concurrency & AOT):** Kita akan memasukkan pustaka **`rayon`** untuk pemrosesan paralel multi-core pada PHP, serta **`lazy_static`** untuk menyimpan *cache* global (*Shared Memory*) di RAM server yang tetap hidup selama PHP-FPM menyala.

> [!IMPORTANT]
> **Kompilasi Modul**
> Mengunduh *crate* baru seperti `rayon` dan `rkyv` akan memakan waktu kompilasi (*build time*) sekitar 2-3 menit di lingkungan *sandbox*. Apakah Anda setuju dengan spesifikasi teknologi dan *libraries* yang diajukan ini?

## Proposed Changes

---

### Modifikasi Ekstensi Rust (`rust-wp-accel`)

#### [MODIFY] [Cargo.toml](file:///home/papanlesat/Documents/wp/rust-wp-accel/Cargo.toml)
- Menambahkan dependensi: `rkyv` (untuk Zero-Copy), `rayon` (untuk pemrosesan asinkron paralel), dan `lazy_static` (untuk Memory Caching).

#### [MODIFY] [src/lib.rs](file:///home/papanlesat/Documents/wp/rust-wp-accel/src/lib.rs)
- **Point 4 (Zero-Copy Parser):**
  - Membuat fungsi `fast_zero_copy_read()` yang menyimulasikan pembacaan struktur *byte* mentah (seolah-olah membaca dari cache disk) dan langsung merangkainya ke `Zval` tanpa *parsing* teks.
- **Point 5 (Concurrency & Shared Memory):**
  - `fast_batch_process()`: Menerima *array* raksasa dari PHP dan memprosesnya secara bersamaan (*parallel*) menggunakan `rayon`, memecah beban ke seluruh *CPU cores* yang tersedia.
  - `fast_get_shared_cache()`: Menyimulasikan *Global AOT Cache* dengan mengambil data dari `lazy_static` Mutex Rust yang persisten di RAM (menggantikan kueri `wp_options` repetitif).

---

### Modifikasi WordPress Core (Skrip Pengujian)

#### [MODIFY] [test-accel.php](file:///home/papanlesat/Documents/wp/wordpress/test-accel.php)
- Memperluas skrip pengujian PoC untuk:
  - Memanggil `fast_batch_process` dengan *array* besar dan membuktikan iterasi data dikembalikan dengan utuh (Paralelisme).
  - Memanggil `fast_get_shared_cache` dan menunjukkan bahwa data diambil secara instan dari *shared memory* tanpa menyentuh *database*.

---

## Verification Plan

### Manual Verification
1. Menjalankan `cargo build --release` dengan dependensi baru.
2. Mengeksekusi `php test-accel.php` untuk memvalidasi keluaran dari pemrosesan konkuren dan akses *shared memory*.
3. Mendemonstrasikan bahwa PHP yang tadinya berjalan secara `single-threaded` dan terisolasi per *request*, kini dapat menjalankan kalkulasi `multi-threaded` secara harfiah dan berbagi memori antar *request* berkat ekstensi Rust.

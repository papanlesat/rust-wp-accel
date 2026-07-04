# Analisis Bottleneck Performa WordPress Core (End-to-End)

Selain pemrosesan berkas `theme.json` yang lambat akibat deserialisasi dan manipulasi *array/object* pada PHP, WordPress memiliki sejumlah *bottleneck* performa bawaan yang diwarisi dari arsitektur awalnya (sejak tahun 2003) yang sangat tersentralisasi pada eksekusi repetitif dan desain basis data yang kurang terspesialisasi.

Berdasarkan analisis arsitektur inti (E2E) pada basis kode WordPress, berikut adalah area-area *bottleneck* utama yang memakan siklus CPU secara berlebihan, memicu alokasi memori (*mallocs*) ekstrem, dan berpotensi untuk dioptimalkan menggunakan pendekatan C/Rust (seperti halnya *theme_json_sanitize*).

---

## 1. Options API (Autoloading)
**File Terkait:** `wp-includes/option.php`

### Masalah:
Saat WordPress pertama kali memuat, fungsi `wp_load_alloptions()` dieksekusi. Fungsi ini akan mengambil semua baris dari tabel `wp_options` yang memiliki nilai `autoload = 'yes'`.
Masalahnya terletak pada bagaimana _plugin_ dan _theme_ pihak ketiga sering kali menyalahgunakan tabel ini dengan menyimpan data besar dalam bentuk *serialized array* (seperti data transien, pengaturan kompleks, atau bahkan HTML).

### Mengapa Inefisien?
- **Unserialize Overhead:** PHP harus menjalankan fungsi internal `unserialize()` untuk setiap *string* pengaturan. Deserialisasi berulang pada setiap *request* memicu _mallocs_ massal dan membangun struktur array di memori berulang kali.
- **Memory Bloat:** Objek cache akan menampung seluruh *array* besar ini dalam memori `$_wp_options` atau Redis/Memcached.

### Potensi Solusi (Rust/C):
- Mengekstraksi fungsi `wp_load_alloptions()` atau `maybe_unserialize()` ke *engine* eksternal yang jauh lebih efisien dalam mem-parsing dan menyimpan *struct* data secara terpusat (menggunakan _memory mapping_ atau format biner ringan) dibanding *PHP arrays*.

## 2. Abstraksi Basis Data (`wpdb`)
**File Terkait:** `wp-includes/class-wpdb.php`

### Masalah:
Kelas `wpdb` adalah lapisan tunggal bagi WP untuk berkomunikasi dengan database MySQL/MariaDB. Setiap kueri yang mengembalikan data menggunakan `get_results()`.

### Mengapa Inefisien?
- WordPress secara *default* akan menampung **keseluruhan** hasil kueri ke dalam properti kelas `$this->last_result` sebagai *array of objects/arrays*.
- Jika kueri mengembalikan 5,000 baris, PHP akan melakukan ribuan kali alokasi objek dinamis (melalui `get_object_vars` di baris 3155-3190).
- Hal ini menyebabkan lonjakan penggunaan RAM secara tiba-tiba (*spikes*) yang mengakibatkan PHP memicu *Garbage Collector* secara agresif.

### Potensi Solusi (Rust/C):
- Menggantikan implementasi MySQLi/PDO *driver* milik WP dengan lapisan Rust yang langsung berinteraksi dengan MariaDB, hanya mentransfer blok memori (*buffer*) spesifik saat benar-benar diminta, alih-alih me-mapping semuanya ke objek PHP di awal.

## 3. Sistem Lokalisasi / Terjemahan (POMO)
**File Terkait:** `wp-includes/l10n.php` dan `wp-includes/pomo/mo.php`

### Masalah:
Situs WordPress non-Inggris memuat ratusan hingga ribuan string terjemahan dari berkas `.mo` (*Machine Object*) pada saat memuat (saat `load_textdomain()` dipanggil).

### Mengapa Inefisien?
- Parser MO bawaan WP dijalankan sepenuhnya pada *userspace* PHP (`POMO_FileReader`). Ia membuka berkas biner, mem-parsing blok demi blok menggunakan siklus `unpack()` PHP yang lambat, dan memuat setiap *string* menjadi *array* berindeks di dalam memori eksekusi tunggal.
- Pemuatan `.mo` bisa menambah waktu eksekusi (`TTFB - Time to First Byte`) sebesar 50ms - 200ms per *request* hanya untuk menerjemahkan GUI dan API.

### Potensi Solusi (Rust/C):
- Parser `.mo` dapat sepenuhnya ditulis ulang dalam C/Rust dan di-bind melalui FFI. Sistem Rust dapat menggunakan fungsi OS `mmap()` untuk sekadar memetakan berkas translasi langsung ke RAM tanpa perlu melakukan alokasi struktur *array* sedikitpun (`Zero-copy deserialization`).

## 4. Rewrite Rules & Routing URL
**File Terkait:** `wp-includes/class-wp-rewrite.php`

### Masalah:
Tidak seperti _framework_ modern yang menggunakan pohon (*Radix Tree* atau *Trie*) untuk *routing*, WordPress menggunakan arsitektur lawas berupa himpunan *Regular Expression* raksasa. 

### Mengapa Inefisien?
- Tabel _rewrite rules_ dikompilasi menjadi satu _array_ besar yang disimpan di `wp_options`.
- Saat sebuah URL diakses (misal `/category/news/page/2/`), fungsi `WP::parse_request()` me-loop *array* tersebut secara berurutan dan menjalankan `preg_match` PHP ratusan kali hingga menemukan pola yang cocok.
- Semakin banyak _Custom Post Types_ (CPT) yang terdaftar, semakin banyak komputasi regex yang terbuang percuma.

### Potensi Solusi (Rust/C):
- Mesin regex berkecepatan tinggi pada Rust (seperti *crate* `regex` yang tidak memiliki _backtracking catastrophic_) dipadukan dengan struktur data *Trie* dapat menguraikan *routing* URL WordPress 40-100x lebih cepat daripada `preg_match` linier di PHP.

---

### Kesimpulan Strategis

Jika modul `rust-wp-accel` yang telah kita bangun ini diperluas, target yang akan memberikan imbal hasil (*ROI*) performa terbesar selanjutnya adalah:
1. Menimpa fungsi **`maybe_unserialize`** (mempercepat Options API dan Post Meta).
2. Menulis ulang **sistem _routing_ regex** WordPress.
3. Mewakili **POMO File Reader** dengan *memory-mapped struct* berbasis Rust.

Ketiga celah ini, ditambah intersepsi `theme.json` yang sudah ada, berpotensi memangkas hampir **70% dari _overhead_ internal WordPress** tanpa mengubah ekosistem *plugin* yang sudah ada.

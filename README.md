# SIGAP-JPL — Sistem Informasi & Grafis Analisis Perlintasan

Aplikasi web berbasis **Laravel** untuk memantau **JPL (perlintasan sebidang)**
yang dipantau CCTV di sepanjang proyek **SRRL**: menghitung mobil, motor,
sepeda, bus, truk, dan orang yang lewat, sekaligus **mendeteksi potensi
bahaya** — objek yang berhenti/diam terlalu lama di area rel. Deteksi &
tracking dilakukan oleh **microservice Python (FastAPI + YOLOv8 + ByteTrack)**.
Arsitektur berbasis Docker lokal ini bisa dijalankan **offline** (server
on-site dekat JPL, tanpa internet setelah image dibangun) maupun **online**
(diakses lewat jaringan bila di-deploy ke server dengan akses jaringan).

## Fitur utama

- **Multi-zona poligon per video** — gambar bebas beberapa zona di atas video:
  - *Zona Arah* — menghitung objek yang melewatinya (per kelas & per zona,
    mis. "Rel Kiri", "Rel Kanan").
  - *Zona Bahaya* — menandai objek yang diam/berhenti di area tersebut
    melebihi ambang waktu tertentu (default 5 detik) sebagai **safety event**,
    lengkap dengan foto bukti (snapshot) otomatis.
- **Jejak lintasan (trail)** per objek dengan warna unik & konsisten per ID,
  serta overlay nama lokasi JPL + timestamp pada video hasil.
- **Multi-lokasi JPL** — setiap video terhubung ke satu lokasi JPL terdaftar;
  dashboard menampilkan ringkasan & riwayat per lokasi.
- **Dashboard Offline & laporan historis** — ringkasan total video/objek/safety
  event, filter berdasarkan lokasi & rentang tanggal, dan **export CSV**.
- **Dashboard Online** — tayangan CCTV langsung per lokasi JPL, aktif begitu
  Administrator mengisi URL CCTV (HLS/MP4/embed) lewat menu Lokasi JPL.
- **Deteksi live terjadwal** — Administrator bisa menjadwalkan rentang waktu
  mulai/selesai (maks. 6 jam) untuk memproses stream CCTV sebuah lokasi
  **secara langsung lewat YOLOv8**, tanpa merekam ke file dulu. Zona
  digambar ulang di atas snapshot terkini yang diambil otomatis dari stream
  saat penjadwalan dibuat. Hasilnya (video hasil deteksi & tracking, total per
  kategori, rincian per zona, safety event) tampil di panel "Video Hasil
  Deteksi & Tracking" pada Dashboard Online, di sebelah tayangan CCTV live.
  Lihat bagian "Cara pakai" poin 9 di bawah.
- **Login & role pengguna** — seluruh halaman (kecuali endpoint callback dari
  detector-service) dilindungi otentikasi. Dua role: **Administrator** (akses
  penuh: Unggah Video, Lokasi JPL, Kelola Pengguna) dan **User** (hanya bisa
  membuka menu Dashboard, baik Online maupun Offline). Lihat bagian
  "Login, akun admin & manajemen pengguna" di bawah.
- Kelas objek yang dideteksi: **person, bicycle, car, motorcycle, bus, truck**.

## Arsitektur

```
┌─────────────┐   upload video + zona     ┌──────────────────┐
│   Browser   │ ─────────────────────────▶│  Laravel (app)     │
│ (gambar     │                            │  - Upload & UI      │
│  zona)      │◀──────status/hasil─────────│  - Queue job        │
└─────────────┘                            └─────────┬─────────┘
                                                      │ HTTP POST /process
                                                      ▼
                                            ┌──────────────────┐
                                            │ Python detector    │
                                            │ FastAPI + YOLOv8    │
                                            │ + ByteTrack          │
                                            │ + zona poligon        │
                                            └─────────┬─────────┘
                                                      │ HTTP POST callback
                                                      ▼
                                            ┌──────────────────┐
                                            │  Laravel (app)     │
                                            │  simpan counts +    │
                                            │  safety events ke DB │
                                            └──────────────────┘
```

- **laravel-app/** — aplikasi web: kelola lokasi JPL, upload video + gambar
  zona poligon di atas `<video>`, dashboard hasil (chart + tabel + video
  beranotasi + daftar safety event).
- **detector-service/** — service Python yang menjalankan YOLOv8, melacak
  objek antar-frame (ByteTrack), menghitung per zona arah, mendeteksi dwell
  time di zona bahaya, menggambar trail & overlay, lalu mengirim hasil balik
  ke Laravel via callback HTTP.
- Kedua service tersambung lewat `docker-compose.yml` dan berbagi volume
  video (`videos_data`) sehingga Python bisa membaca file yang diunggah lewat Laravel.

## Cara menjalankan (butuh Docker & Docker Compose)

```bash
cd vehicle-counter
docker compose up --build
```

Build pertama kali akan mengunduh dependency PHP (Composer), dependency
Python (termasuk PyTorch untuk YOLOv8n), dan bobot model `yolov8n.pt` —
pastikan komputer Anda terhubung internet dan build ini bisa memakan
waktu beberapa menit.

Setelah semua container jalan:
- Buka **http://localhost:8000** — akan diarahkan ke halaman **login** dulu.
  Login pakai akun yang diset lewat `ADMIN_EMAIL`/`ADMIN_PASSWORD` di `.env`
  (default untuk lokal: `admin@sigap-jpl.local` / `ubah-password-ini` —
  **wajib diganti**, lihat bagian "Login & akun admin" di bawah).
- **http://localhost:8000/dashboard** — ringkasan & laporan semua JPL.
- **http://localhost:8000/locations** — kelola daftar lokasi JPL.
- Service detector bisa dicek langsung di **http://localhost:8001/health**.

## Login, akun admin & manajemen pengguna

Tidak ada halaman registrasi publik. Satu akun Administrator awal
dibuat/diperbarui otomatis setiap container `app`/`queue` start, berdasarkan
environment variable:

- `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME` (opsional, default "Administrator").

Kalau kedua variable itu tidak diset, tidak ada akun yang dibuat/diubah
(command `sigap:ensure-admin` dilewati). Untuk mengganti password akun ini,
cukup ubah `ADMIN_PASSWORD` lalu restart/redeploy container — akun dengan
email yang sama akan diperbarui passwordnya (bukan dibuat akun baru). Akun
ini selalu diberi role `admin` dan status aktif oleh `sigap:ensure-admin`,
jadi tidak akan pernah terkunci keluar meski di-nonaktifkan lewat menu
Kelola Pengguna.

Untuk akun-akun lain (anggota tim yang hanya perlu memantau Dashboard, atau
Administrator tambahan), login sebagai Administrator lalu buka menu
**Kelola Pengguna** untuk menambah, menonaktifkan, reset password, atau
menghapus akun. Ada dua role:

- **admin** — akses penuh: Unggah Video, Lokasi JPL, Kelola Pengguna, plus
  kedua Dashboard.
- **user** — hanya menu Dashboard (Online & Offline); mencoba membuka URL
  halaman admin secara langsung akan otomatis diarahkan balik ke Dashboard.

Sistem selalu menjaga agar minimal ada satu akun Administrator aktif — akun
admin terakhir tidak bisa dinonaktifkan/dihapus lewat menu Kelola Pengguna.

## Cara pakai

1. Buka **/locations**, tambahkan lokasi JPL (kode + nama), atau langsung
   tambahkan saat upload video (lihat langkah 3).
2. Buka halaman utama, pilih file video (mis. `A.Yani-Jemursari.mp4`).
3. Pilih lokasi JPL yang sudah ada, atau pilih **"+ Tambah lokasi baru"**.
4. Video akan diputar di preview — klik pada video untuk menambah titik
   poligon (minimal 3 titik), isi nama zona & pilih tipe:
   - **Zona Arah** untuk menghitung objek yang lewat (mis. "Rel Kiri", "Rel Kanan").
   - **Zona Bahaya** untuk area rel/perlintasan yang perlu dipantau.

   Klik **Simpan Zona**, ulangi untuk menggambar zona lain jika perlu.
5. Klik **Unggah & Proses**. Halaman detail video akan otomatis memperbarui
   status setiap 3 detik.
6. Setelah selesai, halaman menampilkan:
   - Video hasil deteksi (kotak pembatas + ID + jejak lintasan + zona + overlay lokasi/waktu).
   - Grafik & tabel rincian jumlah per kelas objek per zona.
   - Daftar **safety event** (objek yang diam di zona bahaya) lengkap foto bukti.
7. (Opsional) Di **/locations**, isi kolom URL CCTV pada lokasi yang sudah
   punya kamera live — lokasi itu langsung muncul di **Dashboard Online**
   untuk semua pengguna.
8. Bagikan akun dengan role **user** (dibuat lewat menu Kelola Pengguna)
   kepada anggota tim yang hanya perlu memantau **Dashboard** — mereka tidak
   melihat menu Unggah Video/Lokasi JPL/Kelola Pengguna sama sekali.
9. Untuk menjadwalkan **deteksi live**: di **/locations**, pada lokasi yang
   sudah punya URL CCTV, klik **"+ Jadwalkan Deteksi Live"**. Isi waktu
   mulai & selesai (di masa depan, maks. 6 jam), klik **"Ambil Snapshot dari
   CCTV"** untuk mengambil gambar terkini dari stream, lalu gambar zona di
   atasnya (sama seperti langkah 4) dan klik **Simpan Jadwal**. Saat waktu
   mulai tiba, sistem otomatis memproses stream CCTV lokasi tersebut secara
   langsung lewat YOLOv8 (tanpa merekam ke file dulu) sampai waktu selesai.
   Hasilnya muncul di panel "Video Hasil Deteksi & Tracking" pada Dashboard
   Online untuk lokasi tersebut, diperbarui otomatis seperti halaman detail
   video biasa.

## Deploy ke VPS via Coolify

Contoh ini memakai domain `sigap-jpl.batimulia.com` dan instance Coolify di
`coolify.batimulia.com`, tapi langkahnya sama untuk domain/instance lain.

**1. Push project ini ke repository Git**

Coolify men-deploy dari source code di Git (bukan cuma file
`docker-compose.yml`, tapi juga `Dockerfile` & seluruh source-nya perlu
tersedia agar bisa di-build), jadi:

```bash
cd vehicle-counter
git init
git add .
git commit -m "Initial commit SIGAP-JPL"
git remote add origin <url-repo-anda>   # mis. GitHub/GitLab, sebaiknya privat
git push -u origin main
```

**2. Arahkan DNS**

Buat **A record** untuk `sigap-jpl.batimulia.com` yang menunjuk ke alamat IP
VPS tempat aplikasi akan dijalankan (server yang sudah ditambahkan sebagai
*Server/Destination* di Coolify — boleh server yang sama dengan
`coolify.batimulia.com`, boleh juga server lain).

**3. Buat resource di Coolify**

1. Di `coolify.batimulia.com` → **New Resource** → pilih tipe **Docker Compose**,
   hubungkan ke repository yang baru dibuat (via Deploy Key/GitHub App/repo publik),
   pilih branch, dan pastikan Coolify membaca `docker-compose.yml` di root repo.
2. Setelah compose terbaca, Coolify menampilkan 3 service: `app`, `queue`, `detector`.
3. **Assign domain hanya ke service `app`** (isi field domain dengan
   `https://sigap-jpl.batimulia.com:8000` — port 8000 karena container
   `app` listen di port itu). **Jangan** kasih domain ke `queue` maupun
   `detector` — keduanya harus tetap privat, hanya dijangkau lewat jaringan
   Docker internal antar-container.
4. Isi environment variables di tab *Environment Variables* (Coolify otomatis
   mendeteksinya dari `${VAR}` di `docker-compose.yml`):
   - `DETECTOR_CALLBACK_SECRET` — **wajib diganti** dari default, isi string
     acak panjang (mis. hasil `openssl rand -hex 32`).
   - `APP_KEY` — isi dengan `base64:` + 32 byte acak (mis. jalankan
     `openssl rand -base64 32` lalu tambahkan prefix `base64:` di depannya).
     Ini penting supaya kunci enkripsi Laravel **stabil antar redeploy**
     (tanpa ini, aplikasi tetap jalan tapi generate kunci baru tiap redeploy).
   - `APP_URL` — isi `https://sigap-jpl.batimulia.com`.
   - `ADMIN_EMAIL` & `ADMIN_PASSWORD` — kredensial login admin (lihat bagian
     "Login & akun admin" di atas). **Wajib diisi dengan password yang kuat**,
     ini akan jadi satu-satunya pintu masuk aplikasi.
   - (opsional) `DANGER_DWELL_SECONDS`, `MODEL_NAME`.
5. Klik **Deploy**. Build pertama memakan waktu beberapa menit (unduh
   PyTorch/YOLOv8 untuk `detector`). Coolify otomatis menerbitkan sertifikat
   SSL (Let's Encrypt) untuk domain yang di-assign, selama DNS sudah mengarah
   dengan benar.
6. Setelah selesai, buka **https://sigap-jpl.batimulia.com** dan login
   dengan `ADMIN_EMAIL`/`ADMIN_PASSWORD` yang tadi diisi.

**Catatan penting sebelum publik-facing:**

- Semua halaman sudah dilindungi login, tapi baru mendukung satu peran
  (tidak ada level admin/staff terpisah) dan belum ada audit log siapa
  mengunggah video apa. Cukup aman untuk penggunaan tim internal SRRL saat ini.
- `php artisan serve` dipakai sebagai web server bawaan Laravel — cukup untuk
  trafik ringan/internal seperti ini, tapi kalau ke depan butuh menangani
  banyak upload video bersamaan, pertimbangkan upgrade ke php-fpm + nginx.
- Pastikan disk VPS cukup besar: setiap video CCTV yang diunggah + versi
  hasil anotasinya (ukuran serupa) disimpan permanen di volume `videos_data`.

## Struktur proyek

```
vehicle-counter/
├── docker-compose.yml
├── laravel-app/                     # Aplikasi Laravel 11 (PHP 8.2)
│   ├── app/
│   │   ├── Models/                   User, JplLocation, Video, VideoZone, CountResult, SafetyEvent
│   │   ├── Http/Controllers/         AuthController, VideoController, VideoCallbackController,
│   │   │                             JplLocationController, DashboardController
│   │   ├── Console/Commands/         EnsureAdminUser.php (buat/update akun admin dari env)
│   │   ├── Jobs/ProcessVideoJob.php  (queue job -> panggil detector)
│   │   └── Services/DetectorClient.php
│   ├── database/migrations/
│   ├── resources/views/
│   │   ├── auth/                     halaman login
│   │   ├── videos/                   upload (zona poligon) + detail hasil
│   │   ├── dashboard/                ringkasan & riwayat multi-JPL
│   │   └── locations/                kelola lokasi JPL
│   └── Dockerfile
└── detector-service/                 # Microservice Python (FastAPI)
    ├── app/main.py                   endpoint /process: YOLOv8 + tracking + overlay
    ├── app/tracker.py                ZoneTracker: hitung per zona + deteksi dwell time
    └── Dockerfile
```

## Kustomisasi

- **Ganti model**: set env `MODEL_NAME` di `docker-compose.yml`
  (mis. `yolov8s.pt` untuk akurasi lebih tinggi, lebih lambat).
- **Ambang waktu safety event**: env `DANGER_DWELL_SECONDS` (default 5 detik) —
  berapa lama objek harus diam di zona bahaya sebelum dianggap potensi masalah.
- **Ambang kepercayaan deteksi**: env `CONF_THRESHOLD` (default 0.35).
- **Panjang jejak lintasan**: env `TRAIL_LENGTH` di detector-service (default 30 titik).
- **Secret callback**: ganti `DETECTOR_CALLBACK_SECRET` di file `.env`
  (root, dibaca oleh docker-compose) untuk produksi.

## Catatan teknis

- Login pakai guard session bawaan Laravel (bukan paket pihak ketiga seperti
  Breeze/Jetstream — ditulis manual agar konsisten dengan sisa aplikasi).
  Akun dikelola lewat command `php artisan sigap:ensure-admin`
  (`app/Console/Commands/EnsureAdminUser.php`), dijalankan otomatis oleh
  `entrypoint.sh` setiap container start berdasarkan `ADMIN_EMAIL`/
  `ADMIN_PASSWORD`. Tidak ada halaman registrasi, reset password, atau
  manajemen banyak user — belum dibutuhkan untuk satu akun admin bersama,
  tapi mudah ditambahkan kalau perlu beberapa akun bernama per anggota tim.
- Kode aplikasi di-*bake* ke dalam image Docker saat build (bukan bind-mount
  dari folder lokal), jadi setelah mengubah kode, jalankan ulang
  `docker compose up --build` agar perubahan terpakai. Yang persisten lewat
  Docker volume hanya data: `app_database` (isi `database.sqlite`) dan
  `videos_data` (video asli + hasil anotasi) — sehingga data tidak hilang
  saat redeploy/rebuild image.
- Port `app` (8000) dan `detector` (8001) di-bind ke `127.0.0.1` saja pada
  host — cukup untuk akses dari komputer/server yang sama (termasuk proxy
  Coolify, yang menjangkau container lewat jaringan Docker internal, bukan
  lewat port host), tapi tidak langsung terbuka ke internet luas.
- Batas ukuran upload PHP (`upload_max_filesize`/`post_max_size`) dinaikkan
  ke 550MB lewat `laravel-app/docker/php.ini`, karena default PHP (2MB/8MB)
  terlalu kecil untuk video CCTV.
- Database menggunakan **SQLite** (file tunggal, tanpa server DB terpisah)
  agar setup tetap ringan; bisa diganti ke MySQL/PostgreSQL lewat
  `laravel-app/config/database.php` + `docker-compose.yml` bila dibutuhkan skala lebih besar.
- Antrian (queue) memakai driver **database** — job hanya memicu request
  HTTP ke detector-service lalu selesai (tidak menunggu proses video),
  sehingga worker tetap ringan. Hasil deteksi dikirim balik secara
  asynchronous lewat endpoint callback (`POST /api/videos/{id}/callback`).
- Zona disimpan sebagai poligon ternormalisasi (0..1) di tabel `video_zones`;
  deteksi "objek di dalam zona" memakai `cv2.pointPolygonTest`. Zona arah
  dihitung sekali per track per zona; zona bahaya melacak durasi objek
  bertahan di dalamnya antar-frame (dengan toleransi ±1 detik untuk deteksi
  yang sempat kedip) sebelum dilaporkan sebagai safety event.
- Saat ini sumber video masih berupa **upload file** (bukan live RTSP) —
  cocok untuk analisis rekaman CCTV JPL secara batch. Integrasi RTSP
  real-time bisa ditambahkan pada tahap berikutnya bila dibutuhkan.
- File-file ini ditulis manual dan diverifikasi lewat pengecekan sintaks
  (`py_compile` untuk Python, pengecekan JSON/YAML, serta unit test logika
  `ZoneTracker`), tapi **belum pernah dijalankan penuh sebagai aplikasi**
  karena lingkungan pembuatan file ini tidak memiliki PHP/Docker terpasang.
  Jalankan `docker compose up --build` dan laporkan bila ada error saat
  build/runtime agar bisa segera diperbaiki.

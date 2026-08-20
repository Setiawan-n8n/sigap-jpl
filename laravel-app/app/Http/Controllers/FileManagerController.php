<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Support\Str;

/**
 * Halaman admin untuk mengelola file video FISIK yang tersimpan di disk
 * "videos" (lihat config/filesystems.php -- root-nya storage_path('app/videos'),
 * sebuah Docker volume bersama antara container app/queue/detector).
 *
 * Dipisah jadi dua kategori file:
 *   - "annotated": video hasil akhir yang sudah digambar kotak/label
 *     deteksinya (kolom `annotated_path`) -- ada untuk video unggahan
 *     MAUPUN sesi live CCTV yang sudah selesai.
 *   - "raw": file video MENTAH yang diunggah admin lewat menu "Unggah
 *     Video" sebelum diproses (kolom `filename`). Sesi live TIDAK pernah
 *     punya file mentah semacam ini -- streamnya diproses langsung tanpa
 *     direkam dulu ke file (lihat _run_live_detection di detector-service),
 *     `filename` untuk video live cuma placeholder "live-{job_id}", BUKAN
 *     nama file sungguhan di disk.
 *
 * Ukuran file SENGAJA dibaca langsung dari disk (bukan disimpan/dihitung
 * dari database) supaya selalu akurat, termasuk kalau ada file yang sudah
 * hilang/terhapus manual di luar aplikasi ini (mis. lewat SSH/Coolify
 * Terminal) -- baris seperti itu otomatis tidak akan muncul di daftar.
 */
class FileManagerController extends Controller
{
    public function index()
    {
        $root = storage_path('app/videos');

        $annotated = Video::query()
            ->whereNotNull('annotated_path')
            ->with(['jplLocation', 'liveCaptureJob'])
            ->latest()
            ->get()
            ->map(fn (Video $video) => $this->fileRow($video, 'annotated', $root))
            ->filter()
            ->values();

        $uploaded = Video::query()
            ->whereDoesntHave('liveCaptureJob')
            ->whereNotNull('filename')
            ->with('jplLocation')
            ->latest()
            ->get()
            ->map(fn (Video $video) => $this->fileRow($video, 'raw', $root))
            ->filter()
            ->values();

        $usedByVideos = $annotated->sum('size_bytes') + $uploaded->sum('size_bytes');

        // @ di depan supaya tidak melempar warning kalau path-nya sempat tidak
        // bisa diakses (mis. volume belum ter-mount sesaat) -- fallback null
        // ditangani rapi di view (tampilkan "tidak diketahui" alih-alih error).
        $diskFree = @disk_free_space($root);
        $diskTotal = @disk_total_space($root);

        return view('files.index', [
            'annotated' => $annotated,
            'uploaded' => $uploaded,
            'usedByVideosHuman' => $this->formatBytes($usedByVideos),
            'diskFreeHuman' => $diskFree !== false ? $this->formatBytes((int) $diskFree) : null,
            'diskTotalHuman' => $diskTotal !== false ? $this->formatBytes((int) $diskTotal) : null,
            'diskUsedPercent' => ($diskFree !== false && $diskTotal !== false && $diskTotal > 0)
                ? round((($diskTotal - $diskFree) / $diskTotal) * 100)
                : null,
        ]);
    }

    /**
     * Unduh salah satu file video ($type: "annotated" atau "raw").
     */
    public function download(Video $video, string $type)
    {
        $path = $this->resolvePath($video, $type);

        abort_unless($path && file_exists($path), 404);

        return response()->download($path, $this->downloadName($video, $type, $path));
    }

    /**
     * Hapus salah satu file video dari disk.
     *
     * Untuk file "annotated", kolom annotated_path juga ikut dikosongkan --
     * halaman lain (Dashboard, detail video) mengecek
     * `$video->annotated_path ? route(...) : ''` sebelum menampilkan player
     * (lihat videos/_result_panel.blade.php); tanpa dikosongkan, video yang
     * sudah dihapus dari sini akan tampil sebagai player rusak/kosong di
     * halaman lain, bukan hilang rapi.
     *
     * Untuk file "raw" (upload mentah), tidak ada kolom yang perlu
     * dikosongkan -- file ini cuma dipakai SEKALI oleh detector-service
     * selagi memproses video (dibaca ulang dari awal ke akhir, lalu tidak
     * disentuh lagi), tidak pernah ditampilkan/dipakai lagi di aplikasi
     * setelah pemrosesan selesai. Aman dihapus kapan saja setelah status
     * video "completed"/"failed" tanpa merusak fitur apa pun.
     */
    public function destroy(Video $video, string $type)
    {
        $path = $this->resolvePath($video, $type);

        abort_unless($path, 404);

        if (file_exists($path)) {
            @unlink($path);
        }

        if ($type === 'annotated') {
            $video->update(['annotated_path' => null]);
        }

        return back()->with('status', 'File video berhasil dihapus dari server.');
    }

    private function resolvePath(Video $video, string $type): ?string
    {
        $root = storage_path('app/videos');

        if ($type === 'annotated') {
            return $video->annotated_path ? $root.'/'.$video->annotated_path : null;
        }

        if ($type === 'raw') {
            if ($video->isFromLiveCapture()) {
                return null;
            }

            return $root.'/'.$video->filename;
        }

        return null;
    }

    private function downloadName(Video $video, string $type, string $path): string
    {
        $suffix = $type === 'annotated' ? 'hasil-deteksi' : 'upload-asli';
        $base = Str::slug($video->jplLocation->name ?? $video->original_name ?? 'video') ?: 'video';
        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'mp4';

        return "{$base}-{$suffix}-{$video->id}.{$ext}";
    }

    private function fileRow(Video $video, string $type, string $root): ?array
    {
        $path = $this->resolvePath($video, $type);

        if (! $path || ! file_exists($path)) {
            return null;
        }

        $bytes = filesize($path) ?: 0;

        return [
            'video' => $video,
            'type' => $type,
            'size_bytes' => $bytes,
            'size_human' => $this->formatBytes($bytes),
            'is_live' => $video->isFromLiveCapture(),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / (1024 ** $power);

        return round($value, $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
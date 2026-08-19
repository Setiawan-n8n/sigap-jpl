<?php

namespace App\Http\Controllers;

use App\Models\JplLocation;
use App\Models\LiveCaptureJob;
use App\Services\DetectorClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Throwable;

/**
 * Penjadwalan deteksi live CCTV per lokasi JPL. Admin menentukan rentang
 * waktu mulai/selesai di masa depan, mengambil snapshot terkini dari stream
 * untuk menggambar ulang zona (arah/bahaya), lalu menyimpan jadwalnya.
 * Eksekusi sebenarnya (memicu detector-service) terjadi belakangan lewat
 * App\Console\Commands\StartDueLiveCaptureJobs saat start_at tiba.
 */
class LiveCaptureJobController extends Controller
{
    public function snapshot(JplLocation $location, DetectorClient $detector)
    {
        abort_unless($location->cctv_url, 422, 'Lokasi ini belum memiliki URL CCTV.');

        try {
            $result = $detector->captureSnapshot($location->cctv_url);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $filename = basename($result['path'] ?? '');

        if ($filename === '') {
            return response()->json(['error' => 'Respons snapshot tidak valid dari detector service.'], 422);
        }

        return response()->json([
            'width' => $result['width'] ?? null,
            'height' => $result['height'] ?? null,
            'image_url' => route('locations.live-jobs.snapshot-image', ['filename' => $filename]),
        ]);
    }

    public function showSnapshot(string $filename)
    {
        $safe = basename($filename);
        $path = storage_path('app/videos/live-snapshots/'.$safe);

        abort_unless(file_exists($path), 404);

        return response()->file($path);
    }

    public function store(Request $request, JplLocation $location)
    {
        abort_unless($location->cctv_url, 422, 'Lokasi ini belum memiliki URL CCTV.');

        $validated = $request->validateWithBag('liveJob', [
            'start_at' => ['required', 'date', 'after:now'],
            'finish_at' => ['required', 'date', 'after:start_at'],
            'zones_json' => ['required', 'string'],
        ], [
            'start_at.after' => 'Waktu mulai harus di masa depan.',
            'finish_at.after' => 'Waktu selesai harus setelah waktu mulai.',
        ]);

        $start = Carbon::parse($validated['start_at']);
        $finish = Carbon::parse($validated['finish_at']);

        $maxHours = 6;
        if ($start->diffInMinutes($finish) > $maxHours * 60) {
            return back()->withErrors(['finish_at' => "Durasi deteksi live maksimal {$maxHours} jam per penjadwalan."], 'liveJob')->withInput();
        }

        $zones = json_decode($validated['zones_json'], true);

        if (! is_array($zones) || count($zones) < 1) {
            return back()->withErrors(['zones_json' => 'Silakan gambar minimal satu zona pada snapshot CCTV.'], 'liveJob')->withInput();
        }

        foreach ($zones as $zone) {
            if (empty($zone['name']) || empty($zone['points']) || count($zone['points']) < 3) {
                return back()->withErrors(['zones_json' => 'Setiap zona harus memiliki nama dan minimal 3 titik.'], 'liveJob')->withInput();
            }
        }

        LiveCaptureJob::create([
            'jpl_location_id' => $location->id,
            'cctv_url' => $location->cctv_url,
            'zones' => $zones,
            'start_at' => $start,
            'finish_at' => $finish,
            'status' => 'scheduled',
        ]);

        return redirect()->route('locations.index')->with(
            'status',
            "Jadwal deteksi live untuk {$location->name} berhasil dibuat ({$start->format('d M Y H:i')} — {$finish->format('d M Y H:i')})."
        );
    }

    public function cancel(LiveCaptureJob $liveCaptureJob)
    {
        abort_unless($liveCaptureJob->isCancelable(), 422, 'Hanya jadwal yang belum dimulai yang bisa dibatalkan.');

        $liveCaptureJob->update(['status' => 'canceled']);

        return redirect()->route('locations.index')->with('status', 'Jadwal deteksi live dibatalkan.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CountResult;
use App\Models\JplLocation;
use App\Models\SafetyEvent;
use App\Models\Video;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $query = Video::query()->with('jplLocation')->latest();

        if ($request->filled('jpl_location_id')) {
            $query->where('jpl_location_id', $request->integer('jpl_location_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('recorded_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('recorded_at', '<=', $request->date('to'));
        }

        $videos = $query->paginate(15)->withQueryString();

        $locations = JplLocation::withCount('videos')->orderBy('name')->get()->map(function (JplLocation $location) {
            $location->safety_event_count = SafetyEvent::whereIn(
                'video_id',
                $location->videos()->pluck('id')
            )->count();

            return $location;
        });

        $summary = [
            'total_videos' => Video::count(),
            'total_completed' => Video::where('status', 'completed')->count(),
            'total_safety_events' => SafetyEvent::count(),
            'total_objects' => (int) CountResult::sum('count'),
        ];

        return view('dashboard.offline', compact('videos', 'locations', 'summary'));
    }

    /**
     * Dashboard Online: daftar lokasi JPL yang sudah diisi URL CCTV oleh
     * Administrator (lihat JplLocationController::updateCctv), plus tayangan
     * CCTV untuk lokasi yang dipilih.
     */
    public function online(Request $request)
    {
        $locations = JplLocation::whereNotNull('cctv_url')->orderBy('name')->get();
        $totalLocations = JplLocation::count();

        $selected = null;
        if ($request->filled('lokasi')) {
            $selected = $locations->firstWhere('id', $request->integer('lokasi'));
        }

        return view('dashboard.online', [
            'locations' => $locations,
            'totalLocations' => $totalLocations,
            'selected' => $selected,
        ]);
    }

    public function export(Request $request)
    {
        $query = Video::query()->with(['jplLocation', 'countResults', 'safetyEvents'])->latest();

        if ($request->filled('jpl_location_id')) {
            $query->where('jpl_location_id', $request->integer('jpl_location_id'));
        }

        $videos = $query->get();
        $filename = 'laporan-sigap-jpl-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($videos) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Lokasi JPL', 'Video', 'Tanggal Rekam', 'Status', 'Kelas Objek', 'Zona', 'Jumlah', 'Total Safety Event']);

            foreach ($videos as $video) {
                $recordedAt = optional($video->recorded_at)->format('Y-m-d H:i');

                if ($video->countResults->isEmpty()) {
                    fputcsv($out, [
                        $video->jplLocation?->name ?? '-',
                        $video->original_name,
                        $recordedAt,
                        $video->status,
                        '-', '-', 0,
                        $video->safetyEvents->count(),
                    ]);

                    continue;
                }

                foreach ($video->countResults as $result) {
                    fputcsv($out, [
                        $video->jplLocation?->name ?? '-',
                        $video->original_name,
                        $recordedAt,
                        $video->status,
                        $result->class_name,
                        $result->zone_name,
                        $result->count,
                        $video->safetyEvents->count(),
                    ]);
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}

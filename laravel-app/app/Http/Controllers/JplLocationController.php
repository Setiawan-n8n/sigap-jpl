<?php

namespace App\Http\Controllers;

use App\Models\JplLocation;
use App\Models\SafetyEvent;
use Illuminate\Http\Request;

class JplLocationController extends Controller
{
    public function index()
    {
        $locations = JplLocation::withCount('videos')->orderBy('name')->get()->map(function (JplLocation $location) {
            $location->safety_event_count = SafetyEvent::whereIn(
                'video_id',
                $location->videos()->pluck('id')
            )->count();

            return $location;
        });

        return view('locations.index', compact('locations'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:jpl_locations,code'],
            'name' => ['required', 'string', 'max:255'],
            'km_position' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
        ], [
            'code.unique' => 'Kode lokasi JPL ini sudah dipakai.',
        ]);

        JplLocation::create($validated);

        return redirect()->route('locations.index')->with('status', 'Lokasi JPL berhasil ditambahkan.');
    }

    /**
     * Mengisi/mengubah/menghapus URL CCTV lokasi -- ini yang mengaktifkan
     * (atau menonaktifkan) Dashboard Online untuk lokasi tersebut.
     */
    public function updateCctv(Request $request, JplLocation $location)
    {
        $validated = $request->validate([
            'cctv_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $url = trim($validated['cctv_url'] ?? '');

        $location->update([
            'cctv_url' => $url !== '' ? $url : null,
            'cctv_added_at' => $url !== '' ? now() : null,
        ]);

        return redirect()->route('locations.index')->with(
            'status',
            $url !== ''
                ? 'URL CCTV disimpan. Dashboard Online kini aktif untuk lokasi ini.'
                : 'URL CCTV dihapus. Dashboard Online untuk lokasi ini dinonaktifkan.'
        );
    }

    public function destroy(JplLocation $location)
    {
        $location->delete();

        return redirect()->route('locations.index')->with('status', 'Lokasi JPL dihapus.');
    }
}

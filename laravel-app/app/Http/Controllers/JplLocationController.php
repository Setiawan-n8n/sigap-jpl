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
}

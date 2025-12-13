<?php


// app/Http/Controllers/API/DeviceConfigurationController.php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;

class DeviceConfigurationController extends Controller
{
    public function show(Device $device)
    {
        return response()->json([
            'device' => $device->load('configuration'),
        ]);
    }

    public function update(Request $request, Device $device)
    {
        $validated = $request->validate([
            'custom_name' => 'nullable|string|max:255',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'marker_icon' => 'nullable|string',
            'vehicle_image' => 'nullable|string',
            'route_type' => 'nullable|in:car,walking,bicycle',
            'tracking_disabled' => 'boolean',
            'sharing_enabled' => 'boolean',
            'show_live_position' => 'boolean',
            'show_pause_markers' => 'boolean',
            'show_alerts' => 'boolean',
            'fixed_date_range' => 'boolean',
            'date_range_from' => 'nullable|date',
            'date_range_to' => 'nullable|date',
        ]);

        $device->configuration()->updateOrCreate(
            ['device_id' => $device->id],
            $validated
        );

        return response()->json([
            'message' => 'Configuración actualizada exitosamente',
            'device' => $device->fresh()->load('configuration'),
        ]);
    }

    public function uploadImage(Request $request, Device $device)
    {
        $request->validate([
            'image' => 'required|image|max:2048',
        ]);

        $path = $request->file('image')->store('device-images', 'public');

        $device->configuration()->updateOrCreate(
            ['device_id' => $device->id],
            ['vehicle_image' => $path]
        );

        return response()->json([
            'message' => 'Imagen cargada exitosamente',
            'image_url' => asset('storage/' . $path),
        ]);
    }
}
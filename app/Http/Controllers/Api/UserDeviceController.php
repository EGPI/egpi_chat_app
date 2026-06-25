<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'platform' => ['required', 'string', 'max:30', Rule::in(['ios', 'android', 'web'])],
            'fcm_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'os_version' => ['nullable', 'string', 'max:80'],
        ]);

        $user = $request->user();

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        $device = DB::transaction(function () use ($user, $validated): UserDevice {
            UserDevice::query()
                ->where('fcm_token', $validated['fcm_token'])
                ->where(function ($query) use ($user, $validated) {
                    $query->where('user_id', '!=', $user->id)
                        ->orWhere('device_uuid', '!=', $validated['device_uuid']);
                })
                ->delete();

            return UserDevice::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'device_uuid' => $validated['device_uuid'],
                ],
                [
                    'platform' => $validated['platform'],
                    'fcm_token' => $validated['fcm_token'],
                    'device_name' => $validated['device_name'] ?? null,
                    'app_version' => $validated['app_version'] ?? null,
                    'os_version' => $validated['os_version'] ?? null,
                    'is_active' => true,
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                ]
            );
        });

        return response()->json([
            'message' => 'Device registered successfully.',
            'data' => $this->deviceResponse($device),
        ]);
    }

    public function revoke(Request $request, string $deviceUuid): JsonResponse
    {
        $updated = UserDevice::query()
            ->where('user_id', $request->user()->id)
            ->where('device_uuid', $deviceUuid)
            ->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);

        return response()->json([
            'message' => $updated > 0
                ? 'Device revoked successfully.'
                : 'Device was not found.',
        ], $updated > 0 ? 200 : 404);
    }

    private function deviceResponse(UserDevice $device): array
    {
        return [
            'id' => $device->id,
            'device_uuid' => $device->device_uuid,
            'platform' => $device->platform,
            'device_name' => $device->device_name,
            'app_version' => $device->app_version,
            'os_version' => $device->os_version,
            'is_active' => $device->is_active,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'revoked_at' => $device->revoked_at?->toISOString(),
        ];
    }
}

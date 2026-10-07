<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Printer\StorePrinterSnapshot;
use App\Http\Requests\PrintersRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

final class DevicePrintersController
{
    public function store(PrintersRequest $request, StorePrinterSnapshot $storePrinterSnapshot): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $storePrinterSnapshot($device, $request->snapshot());

        return response()->json(['message' => 'Printers accepted.']);
    }
}

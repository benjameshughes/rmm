<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Events\DeviceWakeRequested;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class WakeDevice
{
    /**
     * Broadcast Wake-on-LAN magic packets to every MAC the device reported,
     * several per MAC because a NIC in deep sleep often misses the first.
     * The packet is fire-and-forget: the device coming back online is the
     * only confirmation it worked.
     */
    public function __invoke(Device $device): void
    {
        throw_if(empty($device->mac_addresses), RuntimeException::class, "{$device->hostname} has not reported a MAC address yet");

        $address = config('devices.wake_on_lan.broadcast_address');
        $port = config('devices.wake_on_lan.port');

        $socket = stream_socket_client(
            "udp://{$address}:{$port}",
            $errorCode,
            $errorMessage,
            context: stream_context_create(['socket' => ['so_broadcast' => true]]),
        );

        throw_unless($socket, RuntimeException::class, "Could not open a broadcast socket to {$address}:{$port}: {$errorMessage}");

        collect($device->mac_addresses)
            ->flatMap(fn (string $mac): array => array_fill(0, config('devices.wake_on_lan.packets_per_mac'), $this->magicPacket($mac)))
            ->each(fn (string $packet) => fwrite($socket, $packet));
        fclose($socket);

        DeviceWakeRequested::dispatch($device);

        Log::info('device.wake', [
            'device_id' => $device->id,
            'mac_addresses' => $device->mac_addresses,
            'broadcast_address' => $address,
        ]);
    }

    /**
     * Six 0xFF bytes followed by the MAC sixteen times.
     */
    private function magicPacket(string $mac): string
    {
        return str_repeat("\xFF", 6).str_repeat(hex2bin(str_replace(':', '', $mac)), 16);
    }
}

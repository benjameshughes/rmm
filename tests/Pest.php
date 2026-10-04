<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Netdata v3 responses captured from a real Windows agent (DESKTOP-5ULJ14E,
 * 1 Oct 2026), keyed by the raw payload field the 0.6.0 agent sends them in.
 * Apps are trimmed to ten; every value is as captured.
 *
 * @return array<string, array<string, mixed>>
 */
function netdataWindowsFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/netdata-windows-2026-10-01.json'), true);
}

/**
 * A 60-point window of one Netdata context captured from a Debian 13 LXC (qdrant-test, 4 Oct 2026).
 */
function netdataLinuxFixture(string $context): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/netdata-linux-2026-10-04/{$context}.json"), true);
}

/**
 * Point Wake-on-LAN at a UDP socket on localhost so the test reads the real packets.
 *
 * @return resource
 */
function listenForWakePackets()
{
    $listener = stream_socket_server('udp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND);
    stream_set_blocking($listener, false);

    config([
        'devices.wake_on_lan.broadcast_address' => '127.0.0.1',
        'devices.wake_on_lan.port' => (int) str(stream_socket_get_name($listener, false))->afterLast(':')->value(),
    ]);

    return $listener;
}

/**
 * @param  resource  $listener
 * @return array<int, string>
 */
function receivedWakePackets($listener): array
{
    return collect(range(1, 10))
        ->map(fn (): string|false => stream_socket_recvfrom($listener, 1024))
        ->filter()
        ->values()
        ->all();
}

function magicPacketFor(string $mac): string
{
    return str_repeat("\xFF", 6).str_repeat(hex2bin(str_replace(':', '', $mac)), 16);
}

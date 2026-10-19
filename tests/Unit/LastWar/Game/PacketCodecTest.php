<?php

use App\LastWar\Game\Sfs\PacketCodec;

it('xors the body against its little-endian length', function () {
    $framed = PacketCodec::encode('abcde');

    expect(ord($framed[0]))->toBe(PacketCodec::BINARY | PacketCodec::ENCRYPTED)
        ->and(substr($framed, 1, 2))->toBe(pack('n', 5))
        ->and(substr($framed, 3))->toBe('abcde' ^ "\x05\x00\x00\x00\x05")
        ->and(PacketCodec::xor(PacketCodec::xor('abcde')))->toBe('abcde');
});

it('round-trips small and compressed client frames', function (string $body) {
    $buffer = PacketCodec::encode($body).PacketCodec::encode('next');

    expect(PacketCodec::extractFrame($buffer))->toBe($body)
        ->and(PacketCodec::extractFrame($buffer))->toBe('next')
        ->and($buffer)->toBe('');
})->with([
    'small' => ['hello'],
    'compressed' => [str_repeat('vs-score ', 500)],
]);

it('flags bodies over 1024 bytes as compressed', function () {
    expect(ord(PacketCodec::encode(str_repeat('a', 2000))[0]) & PacketCodec::COMPRESSED)->toBe(PacketCodec::COMPRESSED);
});

it('uses a four-byte length for big frames', function () {
    $body = random_bytes(70_000);
    $framed = PacketCodec::encode($body);

    expect(ord($framed[0]) & PacketCodec::BIG_SIZED)->toBe(PacketCodec::BIG_SIZED)
        ->and(PacketCodec::extractFrame($framed))->toBe($body);
});

it('waits for the rest of a partially buffered frame', function () {
    $framed = PacketCodec::encode('partial');
    $buffer = substr($framed, 0, 5);

    expect(PacketCodec::extractFrame($buffer))->toBeNull()
        ->and($buffer)->toBe(substr($framed, 0, 5));

    $buffer .= substr($framed, 5);

    expect(PacketCodec::extractFrame($buffer))->toBe('partial');
});

it('skips the forward server id on inbound frames', function () {
    $buffer = chr(PacketCodec::BINARY | PacketCodec::FORWARD)."\x00\x07".pack('n', 3).'abc';

    expect(PacketCodec::extractFrame($buffer))->toBe('abc');
});

it('decodes zstd-compressed server frames', function () {
    $body = str_repeat('init ', 400);
    $compressed = zstd_compress($body);
    $buffer = chr(PacketCodec::BINARY | PacketCodec::COMPRESSED | PacketCodec::USE_ZSTD)
        .pack('n', strlen($compressed))
        .pack('N', strlen($body))
        .$compressed;

    expect(PacketCodec::extractFrame($buffer))->toBe($body);
})->skip(! function_exists('zstd_compress'), 'The zstd extension is not installed.');

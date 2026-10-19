<?php

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Sfs\PacketCodec;
use App\LastWar\Game\Sfs\ZstdDecoder;

/**
 * Fixtures were compressed by the zstd extension from synthetic data, so these run
 * without the extension installed, as on Laravel Cloud.
 */
it('decompresses fixtures produced by the reference implementation', function (string $fixture, int $length, string $sha1) {
    $out = ZstdDecoder::decompress(file_get_contents(dirname(__DIR__, 3)."/stubs/zstd/{$fixture}.zst"));

    expect(strlen($out))->toBe($length)
        ->and(sha1($out))->toBe($sha1);
})->with([
    'huffman literals and fse sequences' => ['players-level3', 68_935, '85f4a94778cc40b55632d866cdc6bf2b9b032aa9'],
    'high compression level' => ['players-level19', 68_939, 'e67bb49d215bca521391d415ebc2cf7019ff17fb'],
    'several blocks' => ['mixed-multiblock', 300_000, 'ded684e28a336440cd68bc3f60859537b6507970'],
    'raw block' => ['incompressible', 2_000, '2fc3772db4a9f40fc3a2492669deff73aaaff598'],
]);

it('matches the extension across levels and data shapes', function (string $data) {
    foreach ([-5, 1, 3, 9, 19, 22] as $level) {
        expect(ZstdDecoder::decompress(zstd_compress($data, $level)))->toBe($data);
    }
})->with([
    'empty' => [''],
    'rle' => [str_repeat('a', 50_000)],
    'text' => [str_repeat('The quick brown fox jumps over the lazy dog. ', 2_000)],
    'random' => [random_bytes(4_000)],
    'overlapping matches' => [str_repeat('ab', 30_000).str_repeat('xyz', 10_000)],
])->skip(! function_exists('zstd_compress'), 'The zstd extension is not installed.');

it('skips skippable frames and joins concatenated frames', function () {
    $frame = file_get_contents(dirname(__DIR__, 3).'/stubs/zstd/incompressible.zst');
    $skippable = pack('V', 0x184D2A50).pack('V', 3).'abc';

    expect(strlen(ZstdDecoder::decompress($skippable.$frame.$frame)))->toBe(4_000);
});

it('rejects data that is not zstd', function (string $data) {
    ZstdDecoder::decompress($data);
})->throws(GameClientException::class)->with([
    'wrong magic' => ['not zstd at all'],
    'truncated' => [substr(file_get_contents(dirname(__DIR__, 3).'/stubs/zstd/players-level3.zst'), 0, 500)],
]);

it('decodes zstd game frames without the extension', function () {
    $compressed = file_get_contents(dirname(__DIR__, 3).'/stubs/zstd/players-level3.zst');
    $buffer = chr(PacketCodec::BINARY | PacketCodec::COMPRESSED | PacketCodec::USE_ZSTD)
        .pack('n', strlen($compressed))
        .pack('N', 68_935)
        .$compressed;

    expect(sha1(PacketCodec::extractFrame($buffer)))->toBe('85f4a94778cc40b55632d866cdc6bf2b9b032aa9');
});

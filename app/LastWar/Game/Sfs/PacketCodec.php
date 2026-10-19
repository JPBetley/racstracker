<?php

namespace App\LastWar\Game\Sfs;

use App\LastWar\Game\Exceptions\GameClientException;

/**
 * Frames SFS2X bodies for the game's TCP socket.
 *
 * A frame is `[header byte][u16 BE length, or u32 when BIG_SIZED][body]`. Client
 * frames are always "encrypted": XOR against the body length's little-endian bytes,
 * applied after compression. Inbound frames may be zlib or (with USE_ZSTD) zstd
 * compressed, the latter carrying an extra u32 uncompressed length.
 */
final class PacketCodec
{
    public const int BINARY = 0x80;

    public const int ENCRYPTED = 0x40;

    public const int COMPRESSED = 0x20;

    public const int USE_ZSTD = 0x10;

    public const int BIG_SIZED = 0x08;

    public const int FORWARD = 0x04;

    private const int COMPRESSION_THRESHOLD = 1024;

    private const int MAX_FRAME_SIZE = 64 * 1024 * 1024;

    /**
     * Frame an encoded body for sending.
     */
    public static function encode(string $body): string
    {
        $header = self::BINARY | self::ENCRYPTED;

        if (strlen($body) > self::COMPRESSION_THRESHOLD) {
            $body = zlib_encode($body, ZLIB_ENCODING_DEFLATE, 9);
            $header |= self::COMPRESSED;
        }

        $length = strlen($body);

        if ($length > 0xFFFF) {
            $header |= self::BIG_SIZED;
        }

        return chr($header)
            .($length > 0xFFFF ? pack('N', $length) : pack('n', $length))
            .self::xor($body);
    }

    /**
     * Remove one complete frame from the front of the buffer and return its body.
     *
     * Returns null, leaving the buffer untouched, when the frame is not fully buffered yet.
     *
     * @throws GameClientException
     */
    public static function extractFrame(string &$buffer): ?string
    {
        $available = strlen($buffer);

        if ($available < 1) {
            return null;
        }

        $header = ord($buffer[0]);
        $offset = 1;

        if ($header & self::FORWARD) {
            $offset += 2;
        }

        $lengthBytes = $header & self::BIG_SIZED ? 4 : 2;

        if ($available < $offset + $lengthBytes) {
            return null;
        }

        $length = $lengthBytes === 4
            ? unpack('N', substr($buffer, $offset, 4))[1]
            : unpack('n', substr($buffer, $offset, 2))[1];
        $offset += $lengthBytes;

        if ($length > self::MAX_FRAME_SIZE) {
            throw new GameClientException("Game frame too large: {$length} bytes.");
        }

        $zstd = ($header & self::COMPRESSED) && ($header & self::USE_ZSTD);

        if ($zstd) {
            $offset += 4;
        }

        if ($available < $offset + $length) {
            return null;
        }

        $body = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);

        if ($header & self::ENCRYPTED) {
            $body = self::xor($body);
        }

        if (! ($header & self::COMPRESSED)) {
            return $body;
        }

        return $zstd ? self::zstdDecode($body) : self::zlibDecode($body);
    }

    /**
     * XOR the data against the little-endian bytes of its own length.
     */
    public static function xor(string $data): string
    {
        $length = strlen($data);

        if ($length === 0) {
            return $data;
        }

        $key = str_repeat(pack('V', $length), intdiv($length, 4) + 1);

        return $data ^ substr($key, 0, $length);
    }

    private static function zlibDecode(string $body): string
    {
        $out = @zlib_decode($body, self::MAX_FRAME_SIZE);

        if ($out === false) {
            throw new GameClientException('Could not inflate a zlib-compressed game frame.');
        }

        return $out;
    }

    /**
     * Decompress with the zstd extension when it is loaded, else in pure PHP
     * (Laravel Cloud has no zstd extension).
     */
    private static function zstdDecode(string $body): string
    {
        if (! function_exists('zstd_uncompress')) {
            return ZstdDecoder::decompress($body);
        }

        $out = zstd_uncompress($body);

        if ($out === false) {
            throw new GameClientException('Could not decompress a zstd-compressed game frame.');
        }

        return $out;
    }
}

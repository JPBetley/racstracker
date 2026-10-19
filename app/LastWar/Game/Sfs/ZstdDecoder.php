<?php

namespace App\LastWar\Game\Sfs;

use App\LastWar\Game\Exceptions\GameClientException;

/**
 * A pure-PHP Zstandard decompressor (RFC 8878), for hosts without the zstd extension.
 *
 * The game server zstd-compresses every large frame regardless of what the client
 * asks for, and Laravel Cloud cannot load the extension. This follows the zstd
 * project's educational reference decoder: frames, raw/RLE/compressed blocks,
 * Huffman-coded literals and FSE-coded sequences with repeat offsets. Dictionaries
 * are not supported (the game does not use them) and checksums are not verified.
 */
final class ZstdDecoder
{
    private const int FRAME_MAGIC = 0xFD2FB528;

    private const int SKIPPABLE_MAGIC_MASK = 0xFFFFFFF0;

    private const int SKIPPABLE_MAGIC = 0x184D2A50;

    private const array LL_DEFAULT = [4, 3, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 1, 1, 1, 2, 2, 2, 2, 2, 2, 2, 2, 2, 3, 2, 1, 1, 1, 1, 1, -1, -1, -1, -1];

    private const array ML_DEFAULT = [1, 4, 3, 2, 2, 2, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, -1, -1, -1, -1, -1, -1, -1];

    private const array OF_DEFAULT = [1, 1, 1, 1, 1, 1, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, -1, -1, -1, -1, -1];

    private const array LL_BASE = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 20, 22, 24, 28, 32, 40, 48, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384, 32768, 65536];

    private const array LL_BITS = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 3, 3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];

    private const array ML_BASE = [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 37, 39, 41, 43, 47, 51, 59, 67, 83, 99, 131, 259, 515, 1027, 2051, 4099, 8195, 16387, 32771, 65539];

    private const array ML_BITS = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 3, 3, 4, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];

    private const int MAX_OUTPUT = 64 * 1024 * 1024;

    private string $data;

    /**
     * @var array<int, int>
     */
    private array $bytes;

    /**
     * Start byte of the backward bitstream being read.
     */
    private int $streamStart = 0;

    /**
     * Bit offset into the backward bitstream; goes negative once it is exhausted.
     */
    private int $streamOffset = 0;

    /**
     * Huffman literal table, reused by treeless literal blocks.
     *
     * @var array{symbols: array<int, int>, bits: array<int, int>, maxBits: int}|null
     */
    private ?array $huffman = null;

    /**
     * Sequence FSE tables, reused by the repeat mode.
     *
     * @var array<string, array{symbols: array<int, int>, bits: array<int, int>, base: array<int, int>, log: int}|null>
     */
    private array $tables = [];

    /**
     * @var array{0: int, 1: int, 2: int}
     */
    private array $repeatOffsets = [1, 4, 8];

    private function __construct(string $data)
    {
        $this->data = $data;
        $this->bytes = array_values(unpack('C*', $data) ?: []);
    }

    /**
     * Decompress one or more concatenated zstd frames.
     *
     * @throws GameClientException
     */
    public static function decompress(string $data): string
    {
        return (new self($data))->decodeAll();
    }

    private function decodeAll(): string
    {
        $out = '';
        $position = 0;
        $length = count($this->bytes);

        while ($position < $length) {
            $magic = $this->littleEndian($position, 4);
            $position += 4;

            if ($magic === self::FRAME_MAGIC) {
                $out .= $this->decodeFrame($position);

                continue;
            }

            if (($magic & self::SKIPPABLE_MAGIC_MASK) === self::SKIPPABLE_MAGIC) {
                $position += 4 + $this->littleEndian($position, 4);

                continue;
            }

            throw new GameClientException(sprintf('Not a zstd frame (magic 0x%08X).', $magic));
        }

        return $out;
    }

    private function decodeFrame(int &$position): string
    {
        $descriptor = $this->byte($position++);
        $contentSizeFlag = $descriptor >> 6;
        $singleSegment = ($descriptor >> 5) & 1;
        $hasChecksum = ($descriptor >> 2) & 1;
        $dictionaryIdSize = [0, 1, 2, 4][$descriptor & 3];

        if ($descriptor & 0x08) {
            throw new GameClientException('zstd frame header has its reserved bit set.');
        }

        if (! $singleSegment) {
            $position++;
        }

        if ($dictionaryIdSize > 0 && $this->littleEndian($position, $dictionaryIdSize) !== 0) {
            throw new GameClientException('zstd dictionaries are not supported.');
        }

        $position += $dictionaryIdSize;
        $position += $contentSizeFlag === 0 ? $singleSegment : [0, 2, 4, 8][$contentSizeFlag];

        $this->huffman = null;
        $this->tables = ['ll' => null, 'of' => null, 'ml' => null];
        $this->repeatOffsets = [1, 4, 8];
        $out = '';

        do {
            $header = $this->littleEndian($position, 3);
            $position += 3;
            $isLast = $header & 1;
            $type = ($header >> 1) & 3;
            $size = $header >> 3;

            match ($type) {
                0 => $out .= $this->slice($position, $size),
                1 => $out .= str_repeat($this->slice($position, 1), $size),
                2 => $this->decodeCompressedBlock($position, $position + $size, $out),
                default => throw new GameClientException('zstd block has the reserved type.'),
            };

            $position += $type === 1 ? 1 : $size;

            if (strlen($out) > self::MAX_OUTPUT) {
                throw new GameClientException('zstd frame decompresses beyond the size limit.');
            }
        } while (! $isLast);

        if ($hasChecksum) {
            $position += 4;
        }

        return $out;
    }

    private function decodeCompressedBlock(int $position, int $end, string &$out): void
    {
        $literals = $this->decodeLiterals($position);

        if ($position >= $end) {
            $out .= $literals;

            return;
        }

        $count = $this->byte($position++);

        if ($count === 255) {
            $count = $this->littleEndian($position, 2) + 0x7F00;
            $position += 2;
        } elseif ($count >= 128) {
            $count = (($count - 128) << 8) + $this->byte($position++);
        }

        if ($count === 0) {
            $out .= $literals;

            return;
        }

        $modes = $this->byte($position++);

        if ($modes & 3) {
            throw new GameClientException('zstd sequence modes have reserved bits set.');
        }

        $this->tables['ll'] = $this->sequenceTable($modes >> 6, $position, 'll', self::LL_DEFAULT, 6, 9);
        $this->tables['of'] = $this->sequenceTable(($modes >> 4) & 3, $position, 'of', self::OF_DEFAULT, 5, 8);
        $this->tables['ml'] = $this->sequenceTable(($modes >> 2) & 3, $position, 'ml', self::ML_DEFAULT, 6, 9);

        $this->executeSequences($count, $position, $end, $literals, $out);
    }

    /**
     * Decode the literals section, advancing past it.
     */
    private function decodeLiterals(int &$position): string
    {
        $first = $this->byte($position);
        $type = $first & 3;
        $sizeFormat = ($first >> 2) & 3;

        if ($type < 2) {
            [$regenerated, $headerSize] = match ($sizeFormat) {
                0, 2 => [$first >> 3, 1],
                1 => [($first >> 4) + ($this->byte($position + 1) << 4), 2],
                3 => [($first >> 4) + ($this->byte($position + 1) << 4) + ($this->byte($position + 2) << 12), 3],
            };
            $position += $headerSize;

            if ($type === 0) {
                $literals = $this->slice($position, $regenerated);
                $position += $regenerated;

                return $literals;
            }

            return str_repeat($this->slice($position++, 1), $regenerated);
        }

        [$streams, $headerSize, $sizeBits] = match ($sizeFormat) {
            0 => [1, 3, 10],
            1 => [4, 3, 10],
            2 => [4, 4, 14],
            3 => [4, 5, 18],
        };

        $header = $this->littleEndian($position, $headerSize);
        $mask = (1 << $sizeBits) - 1;
        $regenerated = ($header >> 4) & $mask;
        $compressedSize = ($header >> (4 + $sizeBits)) & $mask;
        $position += $headerSize;
        $end = $position + $compressedSize;

        if ($type === 2) {
            $position = $this->readHuffmanTable($position);
        } elseif ($this->huffman === null) {
            throw new GameClientException('zstd treeless literals block without a previous Huffman table.');
        }

        if ($streams === 1) {
            $literals = $this->decodeHuffmanStream($position, $end, $regenerated);
        } else {
            $sizes = [$this->littleEndian($position, 2), $this->littleEndian($position + 2, 2), $this->littleEndian($position + 4, 2)];
            $start = $position + 6;
            $segment = intdiv($regenerated + 3, 4);
            $literals = '';

            for ($i = 0; $i < 4; $i++) {
                $streamEnd = $i < 3 ? $start + $sizes[$i] : $end;
                $literals .= $this->decodeHuffmanStream($start, $streamEnd, $i < 3 ? $segment : $regenerated - 3 * $segment);
                $start = $streamEnd;
            }
        }

        $position = $end;

        return $literals;
    }

    /**
     * Read a Huffman tree description and build the literal decoding table.
     */
    private function readHuffmanTable(int $position): int
    {
        $header = $this->byte($position++);
        $weights = [];

        if ($header >= 128) {
            $count = $header - 127;

            for ($i = 0; $i < $count; $i++) {
                $byte = $this->byte($position + ($i >> 1));
                $weights[] = $i % 2 === 0 ? $byte >> 4 : $byte & 15;
            }

            $position += ($count + 1) >> 1;
        } else {
            $end = $position + $header;
            [$frequencies, $accuracyLog, $streamStart] = $this->readFseDescription($position, 6);
            $table = $this->buildFseTable($frequencies, $accuracyLog);

            $this->openBackwardStream($streamStart, $end);
            $state1 = $this->readBackward($accuracyLog);
            $state2 = $this->readBackward($accuracyLog);

            while (true) {
                $weights[] = $table['symbols'][$state1];
                $state1 = $table['base'][$state1] + $this->readBackward($table['bits'][$state1]);

                if ($this->streamOffset < 0) {
                    $weights[] = $table['symbols'][$state2];

                    break;
                }

                $weights[] = $table['symbols'][$state2];
                $state2 = $table['base'][$state2] + $this->readBackward($table['bits'][$state2]);

                if ($this->streamOffset < 0) {
                    $weights[] = $table['symbols'][$state1];

                    break;
                }

                if (count($weights) > 255) {
                    throw new GameClientException('zstd Huffman weights overflow.');
                }
            }

            $position = $end;
        }

        $total = 0;

        foreach ($weights as $weight) {
            $total += $weight > 0 ? 1 << ($weight - 1) : 0;
        }

        if ($total === 0) {
            throw new GameClientException('zstd Huffman table has no weights.');
        }

        $maxBits = self::highestBit($total) + 1;
        $remaining = (1 << $maxBits) - $total;

        if ($remaining & ($remaining - 1)) {
            throw new GameClientException('zstd Huffman weights do not complete a tree.');
        }

        $weights[] = self::highestBit($remaining) + 1;

        $this->huffman = $this->buildHuffmanTable($weights, $maxBits);

        return $position;
    }

    /**
     * @param  list<int>  $weights
     * @return array{symbols: array<int, int>, bits: array<int, int>, maxBits: int}
     */
    private function buildHuffmanTable(array $weights, int $maxBits): array
    {
        $bits = array_map(fn (int $weight): int => $weight > 0 ? $maxBits + 1 - $weight : 0, $weights);
        $rankCount = array_fill(0, $maxBits + 1, 0);

        foreach ($bits as $length) {
            $rankCount[$length]++;
        }

        $size = 1 << $maxBits;
        $tableBits = array_fill(0, $size, 0);
        $symbols = array_fill(0, $size, 0);
        $rankIndex = array_fill(0, $maxBits + 1, 0);

        for ($i = $maxBits; $i >= 1; $i--) {
            $rankIndex[$i - 1] = $rankIndex[$i] + $rankCount[$i] * (1 << ($maxBits - $i));

            for ($j = $rankIndex[$i]; $j < $rankIndex[$i - 1]; $j++) {
                $tableBits[$j] = $i;
            }
        }

        foreach ($bits as $symbol => $length) {
            if ($length === 0) {
                continue;
            }

            $code = $rankIndex[$length];
            $span = 1 << ($maxBits - $length);

            for ($j = $code; $j < $code + $span; $j++) {
                $symbols[$j] = $symbol;
            }

            $rankIndex[$length] += $span;
        }

        return ['symbols' => $symbols, 'bits' => $tableBits, 'maxBits' => $maxBits];
    }

    private function decodeHuffmanStream(int $start, int $end, int $expected): string
    {
        ['symbols' => $symbols, 'bits' => $bits, 'maxBits' => $maxBits] = $this->huffman;
        $mask = (1 << $maxBits) - 1;

        $this->openBackwardStream($start, $end);
        $state = $this->readBackward($maxBits);
        $out = '';

        while ($this->streamOffset > -$maxBits) {
            $out .= chr($symbols[$state]);
            $length = $bits[$state];
            $state = (($state << $length) + $this->readBackward($length)) & $mask;
        }

        if ($this->streamOffset !== -$maxBits || strlen($out) !== $expected) {
            throw new GameClientException('zstd Huffman literal stream is corrupt.');
        }

        return $out;
    }

    /**
     * Resolve a sequence table for its compression mode, advancing past any description.
     *
     * @param  list<int>  $defaults
     * @return array{symbols: array<int, int>, bits: array<int, int>, base: array<int, int>, log: int}
     */
    private function sequenceTable(int $mode, int &$position, string $name, array $defaults, int $defaultLog, int $maxLog): array
    {
        if ($mode === 2) {
            [$frequencies, $accuracyLog, $position] = $this->readFseDescription($position, $maxLog);

            return $this->buildFseTable($frequencies, $accuracyLog);
        }

        return match ($mode) {
            0 => $this->buildFseTable($defaults, $defaultLog),
            1 => ['symbols' => [$this->byte($position++)], 'bits' => [0], 'base' => [0], 'log' => 0],
            3 => $this->tables[$name] ?? throw new GameClientException("zstd repeat mode without a previous {$name} table."),
        };
    }

    private function executeSequences(int $count, int $position, int $end, string $literals, string &$out): void
    {
        $ll = $this->tables['ll'];
        $of = $this->tables['of'];
        $ml = $this->tables['ml'];

        $this->openBackwardStream($position, $end);
        $llState = $this->readBackward($ll['log']);
        $ofState = $this->readBackward($of['log']);
        $mlState = $this->readBackward($ml['log']);
        $literalPosition = 0;

        for ($i = 0; $i < $count; $i++) {
            $ofCode = $of['symbols'][$ofState];
            $llCode = $ll['symbols'][$llState];
            $mlCode = $ml['symbols'][$mlState];

            if ($llCode > 35 || $mlCode > 52 || $ofCode > 31) {
                throw new GameClientException('zstd sequence code out of range.');
            }

            $offsetValue = (1 << $ofCode) + $this->readBackward($ofCode);
            $matchLength = self::ML_BASE[$mlCode] + $this->readBackward(self::ML_BITS[$mlCode]);
            $literalLength = self::LL_BASE[$llCode] + $this->readBackward(self::LL_BITS[$llCode]);

            if ($i < $count - 1) {
                $llState = $ll['base'][$llState] + $this->readBackward($ll['bits'][$llState]);
                $mlState = $ml['base'][$mlState] + $this->readBackward($ml['bits'][$mlState]);
                $ofState = $of['base'][$ofState] + $this->readBackward($of['bits'][$ofState]);
            }

            $out .= substr($literals, $literalPosition, $literalLength);
            $literalPosition += $literalLength;

            $offset = $this->resolveOffset($offsetValue, $literalLength);
            $from = strlen($out) - $offset;

            if ($offset < 1 || $from < 0) {
                throw new GameClientException('zstd match offset points before the start of the output.');
            }

            $out .= $offset >= $matchLength
                ? substr($out, $from, $matchLength)
                : substr(str_repeat(substr($out, $from, $offset), intdiv($matchLength, $offset) + 1), 0, $matchLength);
        }

        if ($this->streamOffset !== 0) {
            throw new GameClientException('zstd sequence bitstream is corrupt.');
        }

        $out .= substr($literals, $literalPosition);
    }

    /**
     * Turn a sequence's offset value into a distance, maintaining the repeat-offset history.
     */
    private function resolveOffset(int $value, int $literalLength): int
    {
        if ($value > 3) {
            $offset = $value - 3;
            $this->repeatOffsets = [$offset, $this->repeatOffsets[0], $this->repeatOffsets[1]];

            return $offset;
        }

        $index = $value - 1 + ($literalLength === 0 ? 1 : 0);

        if ($index === 0) {
            return $this->repeatOffsets[0];
        }

        $offset = $index < 3 ? $this->repeatOffsets[$index] : $this->repeatOffsets[0] - 1;

        if ($index > 1) {
            $this->repeatOffsets[2] = $this->repeatOffsets[1];
        }

        $this->repeatOffsets[1] = $this->repeatOffsets[0];
        $this->repeatOffsets[0] = $offset;

        return $offset;
    }

    /**
     * Read an FSE table description (forward bitstream).
     *
     * @return array{0: list<int>, 1: int, 2: int} The normalized frequencies, accuracy log, and next byte.
     */
    private function readFseDescription(int $position, int $maxLog): array
    {
        $base = $position * 8;
        $offset = 0;
        $accuracyLog = 5 + $this->bits($base, 4);
        $offset += 4;

        if ($accuracyLog > $maxLog) {
            throw new GameClientException('zstd FSE accuracy log is too large.');
        }

        $remaining = 1 << $accuracyLog;
        $frequencies = [];

        while ($remaining > 0 && count($frequencies) < 256) {
            $width = self::highestBit($remaining + 1) + 1;
            $value = $this->bits($base + $offset, $width);
            $offset += $width;
            $lowMask = (1 << ($width - 1)) - 1;
            $threshold = (1 << $width) - 1 - ($remaining + 1);

            if (($value & $lowMask) < $threshold) {
                $value &= $lowMask;
                $offset--;
            } elseif ($value > $lowMask) {
                $value -= $threshold;
            }

            $probability = $value - 1;
            $remaining -= abs($probability);
            $frequencies[] = $probability;

            if ($probability === 0) {
                do {
                    $repeat = $this->bits($base + $offset, 2);
                    $offset += 2;

                    for ($i = 0; $i < $repeat; $i++) {
                        $frequencies[] = 0;
                    }
                } while ($repeat === 3);
            }
        }

        if ($remaining !== 0) {
            throw new GameClientException('zstd FSE table description is corrupt.');
        }

        return [$frequencies, $accuracyLog, $position + intdiv($offset + 7, 8)];
    }

    /**
     * Build an FSE decoding table from normalized frequencies.
     *
     * @param  list<int>  $frequencies
     * @return array{symbols: array<int, int>, bits: array<int, int>, base: array<int, int>, log: int}
     */
    private function buildFseTable(array $frequencies, int $accuracyLog): array
    {
        $size = 1 << $accuracyLog;
        $symbols = array_fill(0, $size, 0);
        $highThreshold = $size;
        $next = [];

        foreach ($frequencies as $symbol => $frequency) {
            if ($frequency === -1) {
                $symbols[--$highThreshold] = $symbol;
                $next[$symbol] = 1;
            }
        }

        $step = ($size >> 1) + ($size >> 3) + 3;
        $mask = $size - 1;
        $cursor = 0;

        foreach ($frequencies as $symbol => $frequency) {
            if ($frequency <= 0) {
                continue;
            }

            $next[$symbol] = $frequency;

            for ($i = 0; $i < $frequency; $i++) {
                $symbols[$cursor] = $symbol;

                do {
                    $cursor = ($cursor + $step) & $mask;
                } while ($cursor >= $highThreshold);
            }
        }

        if ($cursor !== 0) {
            throw new GameClientException('zstd FSE table is corrupt.');
        }

        $bits = [];
        $baseline = [];

        for ($state = 0; $state < $size; $state++) {
            $descriptor = $next[$symbols[$state]]++;
            $bits[$state] = $accuracyLog - self::highestBit($descriptor);
            $baseline[$state] = ($descriptor << $bits[$state]) - $size;
        }

        return ['symbols' => $symbols, 'bits' => $bits, 'base' => $baseline, 'log' => $accuracyLog];
    }

    /**
     * Start reading a backward bitstream occupying [start, end).
     */
    private function openBackwardStream(int $start, int $end): void
    {
        if ($end <= $start || $end > count($this->bytes)) {
            throw new GameClientException('zstd bitstream is empty or truncated.');
        }

        $last = $this->bytes[$end - 1];

        if ($last === 0) {
            throw new GameClientException('zstd bitstream is missing its end marker.');
        }

        $this->streamStart = $start;
        $this->streamOffset = ($end - $start) * 8 - (8 - self::highestBit($last));
    }

    /**
     * Read bits from the backward stream; bits before its start read as zeros.
     */
    private function readBackward(int $count): int
    {
        if ($count === 0) {
            return 0;
        }

        $this->streamOffset -= $count;

        if ($this->streamOffset >= 0) {
            return $this->bits($this->streamStart * 8 + $this->streamOffset, $count);
        }

        $available = $count + $this->streamOffset;

        if ($available <= 0) {
            return 0;
        }

        return $this->bits($this->streamStart * 8, $available) << -$this->streamOffset;
    }

    /**
     * Read `count` bits (at most 32) starting at an absolute little-endian bit position.
     */
    private function bits(int $position, int $count): int
    {
        $index = $position >> 3;
        $shift = $position & 7;
        $value = 0;

        for ($i = 0, $bytes = ($shift + $count + 7) >> 3; $i < $bytes; $i++) {
            $value |= ($this->bytes[$index + $i] ?? 0) << (8 * $i);
        }

        return ($value >> $shift) & ((1 << $count) - 1);
    }

    private function littleEndian(int $position, int $size): int
    {
        if ($position + $size > count($this->bytes)) {
            throw new GameClientException('Unexpected end of zstd data.');
        }

        $value = 0;

        for ($i = 0; $i < $size; $i++) {
            $value |= $this->bytes[$position + $i] << (8 * $i);
        }

        return $value;
    }

    private function byte(int $position): int
    {
        return $this->bytes[$position] ?? throw new GameClientException('Unexpected end of zstd data.');
    }

    private function slice(int $position, int $length): string
    {
        if ($position + $length > count($this->bytes)) {
            throw new GameClientException('Unexpected end of zstd data.');
        }

        return substr($this->data, $position, $length);
    }

    private static function highestBit(int $value): int
    {
        $bit = -1;

        while ($value > 0) {
            $value >>= 1;
            $bit++;
        }

        return $bit;
    }
}

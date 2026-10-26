<?php

namespace App\Services\Reviews;

/**
 * Reads a video's length in seconds without ffmpeg (not installed here), straight from the
 * container headers:
 *
 * - MP4: the `mvhd` box inside `moov` holds timescale + duration (`moov` may be at either end).
 * - WebM (Matroska/EBML): Segment → Info → TimecodeScale + Duration. Some recorders (e.g. browser
 *   MediaRecorder) leave Duration out; then the length is unknown.
 *
 * Returns null when the length can't be read; callers treat that as "flag for an admin to check".
 */
class VideoDuration
{
    public static function seconds(string $path, string $mime): ?float
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            return match ($mime) {
                'video/mp4', 'application/mp4' => self::mp4($handle, (int) filesize($path)),
                'video/webm' => self::webm($handle, (int) filesize($path)),
                default => null,
            };
        } finally {
            fclose($handle);
        }
    }

    // --- MP4 ---------------------------------------------------------------

    /**
     * @param  resource  $handle
     */
    private static function mp4($handle, int $end): ?float
    {
        $moov = self::findBox($handle, 0, $end, 'moov');
        if ($moov === null) {
            return null;
        }

        $mvhd = self::findBox($handle, $moov[0], $moov[1], 'mvhd');
        if ($mvhd === null) {
            return null;
        }

        fseek($handle, $mvhd[0]);
        $version = ord((string) fread($handle, 1));
        fread($handle, 3); // flags

        if ($version === 1) {
            fread($handle, 16); // creation + modification time (64-bit each)
            $timescale = self::uint32((string) fread($handle, 4));
            $duration = self::uint64((string) fread($handle, 8));
        } else {
            fread($handle, 8);
            $timescale = self::uint32((string) fread($handle, 4));
            $duration = self::uint32((string) fread($handle, 4));
        }

        return $timescale > 0 ? $duration / $timescale : null;
    }

    /**
     * Find a box of $type among the boxes between $start and $end.
     *
     * @param  resource  $handle
     * @return array{0: int, 1: int}|null the box's [content start, end]
     */
    private static function findBox($handle, int $start, int $end, string $type): ?array
    {
        $offset = $start;

        while ($offset + 8 <= $end) {
            fseek($handle, $offset);
            $header = fread($handle, 8);
            if ($header === false || strlen($header) < 8) {
                return null;
            }

            $size = self::uint32(substr($header, 0, 4));
            $boxType = substr($header, 4, 4);
            $headerSize = 8;

            if ($size === 1) { // 64-bit "largesize"
                $size = self::uint64((string) fread($handle, 8));
                $headerSize = 16;
            } elseif ($size === 0) { // runs to the end
                $size = $end - $offset;
            }

            if ($size < $headerSize) {
                return null; // corrupt
            }

            if ($boxType === $type) {
                return [$offset + $headerSize, min($offset + $size, $end)];
            }

            $offset += $size;
        }

        return null;
    }

    // --- WebM / Matroska -----------------------------------------------------

    private const EBML_HEADER = 0x1A45DFA3;

    private const SEGMENT = 0x18538067;

    private const INFO = 0x1549A966;

    private const TIMECODE_SCALE = 0x2AD7B1;

    private const DURATION = 0x4489;

    private const CLUSTER = 0x1F43B675;

    /**
     * @param  resource  $handle
     */
    private static function webm($handle, int $end): ?float
    {
        fseek($handle, 0);
        [$id, $size] = self::ebmlElement($handle);
        if ($id !== self::EBML_HEADER || $size === null) {
            return null;
        }
        fseek($handle, $size, SEEK_CUR);

        [$id, $size] = self::ebmlElement($handle);
        if ($id !== self::SEGMENT) {
            return null;
        }
        $segmentEnd = $size === null ? $end : min(ftell($handle) + $size, $end);

        // Info comes before the first Cluster; stop there.
        while (ftell($handle) < $segmentEnd) {
            [$id, $size] = self::ebmlElement($handle);
            if ($id === null || $id === self::CLUSTER || $size === null) {
                return null;
            }

            if ($id !== self::INFO) {
                fseek($handle, $size, SEEK_CUR);

                continue;
            }

            $infoEnd = ftell($handle) + $size;
            $scale = 1_000_000; // default TimecodeScale: 1 ms in ns
            $duration = null;

            while (ftell($handle) < $infoEnd) {
                [$childId, $childSize] = self::ebmlElement($handle);
                if ($childId === null || $childSize === null) {
                    return null;
                }
                $data = (string) fread($handle, $childSize);

                if ($childId === self::TIMECODE_SCALE) {
                    $scale = self::uintBytes($data);
                } elseif ($childId === self::DURATION) {
                    $duration = match ($childSize) {
                        4 => unpack('G', $data)[1],
                        8 => unpack('E', $data)[1],
                        default => null,
                    };
                }
            }

            return $duration === null ? null : $duration * $scale / 1_000_000_000;
        }

        return null;
    }

    /**
     * Read an EBML element header.
     *
     * @param  resource  $handle
     * @return array{0: ?int, 1: ?int} [id (with marker bits), data size (null = unknown size)]
     */
    private static function ebmlElement($handle): array
    {
        $first = fread($handle, 1);
        if ($first === false || $first === '') {
            return [null, null];
        }

        $byte = ord($first);
        $length = self::vintLength($byte);
        if ($length === null || $length > 4) {
            return [null, null];
        }
        $id = $byte;
        for ($i = 1; $i < $length; $i++) {
            $id = ($id << 8) | ord((string) fread($handle, 1));
        }

        $sizeFirst = ord((string) fread($handle, 1));
        $sizeLength = self::vintLength($sizeFirst);
        if ($sizeLength === null) {
            return [null, null];
        }
        $size = $sizeFirst & (0xFF >> $sizeLength);
        $allOnes = $size === (0xFF >> $sizeLength);
        for ($i = 1; $i < $sizeLength; $i++) {
            $next = ord((string) fread($handle, 1));
            $allOnes = $allOnes && $next === 0xFF;
            $size = ($size << 8) | $next;
        }

        return [$id, $allOnes ? null : $size];
    }

    private static function vintLength(int $byte): ?int
    {
        for ($length = 1; $length <= 8; $length++) {
            if ($byte & (0x80 >> ($length - 1))) {
                return $length;
            }
        }

        return null;
    }

    // --- Bytes ---------------------------------------------------------------

    private static function uint32(string $bytes): int
    {
        return strlen($bytes) === 4 ? unpack('N', $bytes)[1] : 0;
    }

    private static function uint64(string $bytes): int
    {
        return strlen($bytes) === 8 ? unpack('J', $bytes)[1] : 0;
    }

    private static function uintBytes(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }
}

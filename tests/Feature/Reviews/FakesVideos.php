<?php

namespace Tests\Feature\Reviews;

use Illuminate\Http\UploadedFile;

/**
 * Tiny but structurally real MP4 / WebM files, so the server's type detection (finfo) and
 * VideoDuration header parsing run on actual container headers.
 */
trait FakesVideos
{
    private function fakeMp4(float $seconds, string $name = 'clip.mp4', bool $moovAtEnd = false): UploadedFile
    {
        $box = fn (string $type, string $body) => pack('N', 8 + strlen($body)).$type.$body;
        $timescale = 1000;
        $mvhd = $box('mvhd', "\0\0\0\0".pack('N4', 0, 0, $timescale, (int) round($seconds * $timescale)).str_repeat("\0", 80));
        $ftyp = $box('ftyp', 'isom'.pack('N', 0).'isommp42');
        $moov = $box('moov', $mvhd);
        $mdat = $box('mdat', str_repeat("\x00\x11\x22\x33", 256));

        return UploadedFile::fake()->createWithContent($name, $moovAtEnd ? $ftyp.$mdat.$moov : $ftyp.$moov.$mdat);
    }

    private function fakeWebm(?float $seconds, string $name = 'clip.webm'): UploadedFile
    {
        $vint = function (int $length): string {
            for ($bytes = 1; $bytes <= 8; $bytes++) {
                if ($length < (1 << (7 * $bytes)) - 1) {
                    $value = $length | (1 << (7 * $bytes));
                    $out = '';
                    for ($i = $bytes - 1; $i >= 0; $i--) {
                        $out .= chr(($value >> (8 * $i)) & 0xFF);
                    }

                    return $out;
                }
            }

            return '';
        };
        $element = fn (string $id, string $data) => $id.$vint(strlen($data)).$data;

        $header = $element("\x1A\x45\xDF\xA3",
            $element("\x42\x86", "\x01").$element("\x42\xF7", "\x01").$element("\x42\x82", 'webm').$element("\x42\x87", "\x04").$element("\x42\x85", "\x02"));
        $info = $element("\x2A\xD7\xB1", "\x0F\x42\x40") // TimecodeScale 1,000,000 ns = 1 ms
            .($seconds === null ? '' : $element("\x44\x89", pack('E', $seconds * 1000)))
            .$element("\x4D\x80", 'ShoeThrift test');
        $segment = "\x18\x53\x80\x67"."\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF" // unknown size, like live recordings
            .$element("\x15\x49\xA9\x66", $info)
            .$element("\x1F\x43\xB6\x75", str_repeat("\x00", 64)); // a Cluster

        return UploadedFile::fake()->createWithContent($name, $header.$segment);
    }
}

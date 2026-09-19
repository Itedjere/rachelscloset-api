<?php

namespace App\Services\Claims;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * A QR code, as inline SVG.
 *
 * SVG rather than PNG because this is shown on a screen for somebody else's
 * phone to read off, and it has to stay sharp at whatever size the layout
 * gives it. It is also a string, so it rides in the JSON response with the
 * code and the link rather than needing a second authenticated request for
 * an image.
 *
 * Section 16 prints one of these on cardboard, which is a harder problem --
 * ink bleed, a centred logo, a fixed physical size. This is the screen case
 * only, and that section can widen it.
 */
class QrCode
{
    public function svg(string $data, int $size = 320): string
    {
        /*
         * High correction, because the phone reading this is a cheap camera
         * pointed at another phone's screen, at an angle, probably with a
         * reflection across it. The extra redundancy costs a denser code and
         * buys a scan that works first time.
         */
        $result = (new Builder(
            writer: new SvgWriter,
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: 8,
        ))->build();

        return $result->getString();
    }
}

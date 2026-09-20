<?php

namespace App\Services\Qr;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode as Endroid;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * QR codes, for two quite different jobs.
 *
 * SVG AND A MATRIX, NEVER A PNG. A raster writer needs GD or Imagick, and the
 * plan is explicit that this must not be able to fail for an environment
 * reason on shared hosting. SVG needs neither and prints at any size; the
 * matrix needs nothing at all, because the client draws it.
 *
 * Section 11 used this for the claim QR on a tailor's screen. Section 16 uses
 * it for the code printed on a business card, which is why the correction
 * level is a parameter rather than a constant -- the two cases genuinely want
 * different answers. See below.
 */
class QrCode
{
    /**
     * On a screen, read by another phone's camera.
     *
     * High correction, because the reader is a cheap camera pointed at a
     * glossy screen at an angle, probably with a reflection across it. The
     * extra redundancy costs a denser code and buys a scan that works first
     * time, and screen pixels are free.
     */
    public const SCREEN = ErrorCorrectionLevel::High;

    /**
     * On cardboard, read from a purse.
     *
     * Medium, as the plan specifies. Print has no reflections and no
     * viewing angle to speak of, and a denser code is the thing that
     * actually fails here: at business-card size every extra module makes
     * each one smaller, and ink spread on cheap card closes the gaps.
     * Fewer, larger modules scan better on paper.
     */
    public const PRINT = ErrorCorrectionLevel::Medium;

    public function svg(string $data, int $size = 320, ?ErrorCorrectionLevel $level = null): string
    {
        return (new Builder(
            writer: new SvgWriter,
            data: $data,
            errorCorrectionLevel: $level ?? self::SCREEN,
            size: $size,
            margin: 8,
        ))->build()->getString();
    }

    /**
     * The raw grid, one row per string of '0' and '1'.
     *
     * Handed to the browser so it can draw the modules itself, at whatever
     * resolution the canvas needs. That is what makes "save the card as a
     * picture" produce a crisp code at three times card size without any
     * image being fetched, rasterised or scaled -- and without this project
     * gaining an HTML-to-canvas dependency.
     *
     * The quiet zone is deliberately not included: it is margin, the card
     * lays it out, and baking it into the grid would only make the client
     * guess where the code really starts.
     *
     * @return array<int, string>
     */
    public function matrix(string $data, ?ErrorCorrectionLevel $level = null): array
    {
        $matrix = (new MatrixFactory)->create(new Endroid(
            data: $data,
            errorCorrectionLevel: $level ?? self::PRINT,
            size: 300,
            margin: 0,
        ));

        $rows = [];
        $count = $matrix->getBlockCount();

        for ($row = 0; $row < $count; $row++) {
            $line = '';

            for ($column = 0; $column < $count; $column++) {
                $line .= $matrix->getBlockValue($row, $column) ? '1' : '0';
            }

            $rows[] = $line;
        }

        return $rows;
    }
}

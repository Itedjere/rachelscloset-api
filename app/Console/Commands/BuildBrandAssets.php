<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Renders every raster brand asset from one definition.
 *
 * The SVGs in public/brand are authored by hand and are the source of truth for
 * the shape. This regenerates the things that cannot be SVG -- the .ico, the
 * home-screen icons, the social card -- so they can never drift from it.
 *
 * GD rather than a rasteriser, because there is no Imagick on the target host
 * and a dependency for this would be absurd. Everything is drawn at four times
 * the final size and resampled down, which is what gives clean edges on the
 * rounded corners and the letterform.
 */
class BuildBrandAssets extends Command
{
    protected $signature = 'brand:build';

    protected $description = 'Render the favicon, app icons and social card from the brand definition';

    /** The tile, on a 64-unit grid, matching public/brand/mark.svg exactly. */
    private const GRID = 64;

    private const CORNER = 15;

    private const LILAC = [0x82, 0x57, 0xBD];

    private const VIOLET_INK = [0x25, 0x15, 0x40];

    private const BONE = [0xFC, 0xFA, 0xFF];

    private const CHAMPAGNE = [0xE2, 0xCD, 0xA0];

    /** The monogram, on the 64 grid. Must match public/brand/mark.svg. */
    private const CAP = 31.0;

    private const R_CX = 24.5;

    private const C_CX = 40.0;

    private const CY = 31.0;

    /**
     * The lower crossing, where the C comes out in front of the R's leg.
     *
     * [x, y, width, height] on the 64 grid, identical to the clipPath rect in
     * mark.svg. This one rectangle is the entire interlock: without it the C is
     * simply behind the R and the two letters read as stacked rather than
     * woven.
     */
    private const LOCK = [26.0, 32.0, 38.0, 32.0];

    private string $font;

    public function handle(): int
    {
        $this->font = resource_path('brand/ZillaSlab-Bold.ttf');

        if (! is_file($this->font)) {
            $this->error('Missing '.$this->font);
            $this->line('It is the Zilla Slab Bold TTF from Google Fonts (SIL OFL 1.1).');

            return self::FAILURE;
        }

        if (! extension_loaded('gd')) {
            $this->error('GD is not loaded.');

            return self::FAILURE;
        }

        $public = public_path();

        // Home screen and browser icons.
        foreach ([180 => 'apple-touch-icon.png', 192 => 'icon-192.png', 512 => 'icon-512.png'] as $size => $name) {
            $this->writePng($this->tile($size), $public.'/'.$name);
            $this->line("  {$name}");
        }

        /*
         * Maskable: Android crops home-screen icons to whatever shape the
         * launcher wants, so the mark is inset to 60% and the rest is flat
         * colour. Without this the corners of the tile get shaved off.
         */
        $this->writePng($this->maskable(512), $public.'/icon-maskable-512.png');
        $this->line('  icon-maskable-512.png');

        $this->writeIco([16, 32, 48], $public.'/favicon.ico');
        $this->line('  favicon.ico (16, 32, 48)');

        $this->writePng($this->socialCard(), $public.'/og-image.png');
        $this->line('  og-image.png (1200x630)');

        // Lockups, for anywhere the webfont is not available -- print, email,
        // a document handed to somebody. 3x so they survive being scaled.
        $this->writePng($this->lockup(false), $public.'/brand/logo.png');
        $this->writePng($this->lockup(true), $public.'/brand/logo-on-dark.png');
        $this->line('  brand/logo.png, brand/logo-on-dark.png');

        $this->info('Brand assets rebuilt.');

        return self::SUCCESS;
    }

    /* ===================================================================== */

    private function canvas(int $w, int $h)
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        return $im;
    }

    private function rgb($im, array $c)
    {
        return imagecolorallocate($im, $c[0], $c[1], $c[2]);
    }

    /** A rounded rectangle, as four corners and two overlapping bars. */
    private function roundedRect($im, float $x, float $y, float $w, float $h, float $r, $colour): void
    {
        imagefilledrectangle($im, (int) round($x + $r), (int) round($y), (int) round($x + $w - $r), (int) round($y + $h), $colour);
        imagefilledrectangle($im, (int) round($x), (int) round($y + $r), (int) round($x + $w), (int) round($y + $h - $r), $colour);

        foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, (int) round($cx), (int) round($cy), (int) round($r * 2), (int) round($r * 2), $colour);
        }
    }

    /** A stroke with round caps: a bar plus a disc at each end. */
    private function roundLine($im, float $x1, float $y1, float $x2, float $y2, float $weight, $colour): void
    {
        imagesetthickness($im, max(1, (int) round($weight)));
        imageline($im, (int) round($x1), (int) round($y1), (int) round($x2), (int) round($y2), $colour);
        imagesetthickness($im, 1);

        $d = (int) round($weight);
        imagefilledellipse($im, (int) round($x1), (int) round($y1), $d, $d, $colour);
        imagefilledellipse($im, (int) round($x2), (int) round($y2), $d, $d, $colour);
    }

    /**
     * One letter, positioned by its own measured bounding box.
     *
     * A glyph cannot be placed from its point size -- the relationship between
     * the two differs per typeface -- so it is drawn once to measure, then
     * placed from that measurement.
     */
    private function drawGlyph($im, string $ch, float $cx, float $cy, float $capHeight, $colour): void
    {
        $probe = 100;
        $box = imagettfbbox($probe, 0, $this->font, $ch);
        $size = $probe * ($capHeight / abs($box[7] - $box[1]));

        $box = imagettfbbox($size, 0, $this->font, $ch);
        $w = $box[2] - $box[0];
        $h = abs($box[7] - $box[1]);

        imagettftext(
            $im, $size, 0,
            (int) round($cx - $w / 2 - $box[0]),
            (int) round($cy + $h / 2 - ($box[1] - $box[7]) - $box[7]),
            $colour, $this->font, $ch,
        );
    }

    /** The mark, at any pixel size, supersampled four times. */
    private function tile(int $size, bool $withTile = true, bool $simple = false)
    {
        $s = 4;
        $w = $size * $s;
        $u = $w / self::GRID;                 // one grid unit in pixels

        $im = $this->canvas($w, $w);

        if ($withTile) {
            $this->roundedRect($im, 0, 0, $w - 1, $w - 1, self::CORNER * $u, $this->rgb($im, self::LILAC));
        }

        $this->monogram($im, $u, $w, $simple);

        return $this->downsample($im, $w, $size);
    }

    /**
     * The interlocking RC: C behind, R over it, then the C again clipped to the
     * lower crossing so it comes out on top there and only there.
     *
     * The clip is what SVG does with a clipPath; imagesetclip is the same idea
     * and the same rectangle, so the raster and the vector cannot diverge.
     */
    private function monogram($im, float $u, int $w, bool $simple = false): void
    {
        $bone = $this->rgb($im, self::BONE);
        $champagne = $this->rgb($im, self::CHAMPAGNE);

        /*
         * Below about 20px the two letters run into each other and the lock
         * turns to mush, so the smallest icon carries the R alone. This is
         * what a multi-size .ico is for, and it is why the 16px entry does not
         * match the others.
         */
        if ($simple) {
            $this->drawGlyph($im, 'R', 32 * $u, self::CY * $u, 36 * $u, $bone);

            return;
        }

        $this->drawGlyph($im, 'C', self::C_CX * $u, self::CY * $u, self::CAP * $u, $champagne);
        $this->drawGlyph($im, 'R', self::R_CX * $u, self::CY * $u, self::CAP * $u, $bone);

        [$lx, $ly, $lw, $lh] = self::LOCK;
        imagesetclip($im, (int) round($lx * $u), (int) round($ly * $u), (int) round(($lx + $lw) * $u), (int) round(($ly + $lh) * $u));
        $this->drawGlyph($im, 'C', self::C_CX * $u, self::CY * $u, self::CAP * $u, $champagne);
        imagesetclip($im, 0, 0, $w, $w);
    }

    private function maskable(int $size)
    {
        $im = $this->canvas($size, $size);
        imagefilledrectangle($im, 0, 0, $size, $size, $this->rgb($im, self::LILAC));

        // 60% safe zone, which is what every launcher shape fits inside.
        $inner = (int) round($size * 0.6);
        $mark = $this->tile($inner, withTile: false);

        imagecopy($im, $mark, (int) round(($size - $inner) / 2), (int) round(($size - $inner) / 2), 0, 0, $inner, $inner);
        imagedestroy($mark);

        return $im;
    }

    private function downsample($im, int $from, int $to)
    {
        $out = $this->canvas($to, $to);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $to, $to, $from, $from);
        imagedestroy($im);

        return $out;
    }

    /** The card a link to this site unfurls as, in a chat or a timeline. */
    private function socialCard()
    {
        $w = 1200;
        $h = 630;
        $im = $this->canvas($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, $this->rgb($im, self::VIOLET_INK));

        $mark = $this->tile(132);
        imagecopy($im, $mark, 96, 150, 0, 0, 132, 132);
        imagedestroy($mark);

        $bone = $this->rgb($im, self::BONE);
        $champagne = $this->rgb($im, self::CHAMPAGNE);

        imagettftext($im, 62, 0, 96, 372, $bone, $this->font, "Rachel\u{2019}s Closet");
        imagettftext($im, 27, 0, 99, 430, $champagne, $this->font, 'Watch your clothes being made');

        $this->roundLine($im, 99, 476, 232, 476, 4, $champagne);

        return $im;
    }

    /** Mark and wordmark, side by side, on a transparent ground. */
    private function lockup(bool $onDark)
    {
        $markSize = 120;
        $gap = 28;
        $text = "Rachel\u{2019}s Closet";
        $fontSize = 58;

        $box = imagettfbbox($fontSize, 0, $this->font, $text);
        $textW = $box[2] - $box[0];

        $w = $markSize + $gap + $textW + 8;
        $h = $markSize;

        $im = $this->canvas($w, $h);

        $mark = $this->tile($markSize);
        imagecopy($im, $mark, 0, 0, 0, 0, $markSize, $markSize);
        imagedestroy($mark);

        $ink = $onDark ? $this->rgb($im, self::BONE) : $this->rgb($im, self::VIOLET_INK);
        imagettftext($im, $fontSize, 0, $markSize + $gap, (int) round($h / 2 + $fontSize * 0.36), $ink, $this->font, $text);

        return $im;
    }

    private function writePng($im, string $path): void
    {
        imagepng($im, $path, 9);
        imagedestroy($im);
    }

    /**
     * A .ico holding PNG-compressed entries.
     *
     * Every browser still in use understands PNG inside ICO, and it is a great
     * deal smaller than the uncompressed bitmap the format originally carried.
     */
    private function writeIco(array $sizes, string $path): void
    {
        $entries = [];

        foreach ($sizes as $size) {
            ob_start();
            // The 16px entry drops the C -- see monogram().
            imagepng($this->tile($size, simple: $size <= 20), null, 9);
            $entries[] = ['size' => $size, 'data' => ob_get_clean()];
        }

        $count = count($entries);
        $ico = pack('vvv', 0, 1, $count);
        $offset = 6 + 16 * $count;

        foreach ($entries as $entry) {
            $ico .= pack(
                'CCCCvvVV',
                $entry['size'] >= 256 ? 0 : $entry['size'],
                $entry['size'] >= 256 ? 0 : $entry['size'],
                0, 0, 1, 32,
                strlen($entry['data']),
                $offset,
            );
            $offset += strlen($entry['data']);
        }

        foreach ($entries as $entry) {
            $ico .= $entry['data'];
        }

        file_put_contents($path, $ico);
    }
}

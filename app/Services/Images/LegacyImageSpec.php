<?php

namespace App\Services\Images;

/**
 * One parsed legacy JitImage URL: /images/{mode}/{w}/{h}[/{g}]/{source}.
 *
 * Mode semantics are those of thapp/jitimage with the "im" driver (ImageMagick convert):
 *  - 1 resize:      -resize WxH!  exact size, aspect ignored; a 0 side is derived from the aspect ratio.
 *  - 2 crop-scale:  -resize WxH^ -gravity G -extent WxH  cover the box, then crop it at gravity G.
 *  - 4 best fit:    -resize WxH>  shrink to fit inside the box, never enlarge; a 0 side is unconstrained.
 * Modes 0, 3, 5 and 6 existed in the package but no legacy page used them, so they are not routed.
 */
final readonly class LegacyImageSpec
{
    public const MAX_DIMENSION = 2400;

    private const PATTERN = '#\A(?<mode>[124])/(?<w>\d{1,4})/(?<h>\d{1,4})(?:/(?<g>[1-9]))?/(?<source>images/.+)\z#';

    private function __construct(
        public int $mode,
        public int $width,
        public int $height,
        public int $gravity,
        public string $source,
    ) {}

    /**
     * @param  list<string>  $allowedSizes  "mode/w/h" entries
     */
    public static function parse(string $path, array $allowedSizes): ?self
    {
        if (preg_match(self::PATTERN, $path, $m) !== 1) {
            return null;
        }

        [$mode, $width, $height] = [(int) $m['mode'], (int) $m['w'], (int) $m['h']];
        $hasGravity = $m['g'] !== '';

        if (($mode === 2) !== $hasGravity) {
            return null;
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ($width === 0 && $height === 0)) {
            return null;
        }

        if (! in_array($mode.'/'.$width.'/'.$height, $allowedSizes, true)) {
            return null;
        }

        return new self($mode, $width, $height, $hasGravity ? (int) $m['g'] : 5, $m['source']);
    }

    /** Cache identity of the transformation (the source is keyed separately). */
    public function key(): string
    {
        return $this->mode.'/'.$this->width.'/'.$this->height.'/'.$this->gravity;
    }

    /**
     * The resize and optional crop for a source of $srcWidth x $srcHeight.
     *
     * @return array{width: int, height: int, crop: array{width: int, height: int, x: int, y: int}|null}
     */
    public function plan(int $srcWidth, int $srcHeight): array
    {
        return match ($this->mode) {
            1 => $this->planResize($srcWidth, $srcHeight),
            2 => $this->planCropScale($srcWidth, $srcHeight),
            default => $this->planBestFit($srcWidth, $srcHeight),
        };
    }

    /** @return array{width: int, height: int, crop: null} */
    private function planResize(int $srcWidth, int $srcHeight): array
    {
        $width = $this->width === 0 ? (int) floor($this->height * $srcWidth / $srcHeight) : $this->width;
        $height = $this->height === 0 ? (int) floor($this->width * $srcHeight / $srcWidth) : $this->height;

        return ['width' => max(1, $width), 'height' => max(1, $height), 'crop' => null];
    }

    /** @return array{width: int, height: int, crop: array{width: int, height: int, x: int, y: int}} */
    private function planCropScale(int $srcWidth, int $srcHeight): array
    {
        $scale = max($this->width / $srcWidth, $this->height / $srcHeight);
        $width = max($this->width, (int) round($srcWidth * $scale));
        $height = max($this->height, (int) round($srcHeight * $scale));

        $column = ($this->gravity - 1) % 3;
        $row = intdiv($this->gravity - 1, 3);

        return [
            'width' => $width,
            'height' => $height,
            'crop' => [
                'width' => $this->width,
                'height' => $this->height,
                'x' => intdiv(($width - $this->width) * $column, 2),
                'y' => intdiv(($height - $this->height) * $row, 2),
            ],
        ];
    }

    /** @return array{width: int, height: int, crop: null} */
    private function planBestFit(int $srcWidth, int $srcHeight): array
    {
        $scale = 1.0;
        if ($this->width > 0) {
            $scale = min($scale, $this->width / $srcWidth);
        }
        if ($this->height > 0) {
            $scale = min($scale, $this->height / $srcHeight);
        }

        return [
            'width' => max(1, (int) round($srcWidth * $scale)),
            'height' => max(1, (int) round($srcHeight * $scale)),
            'crop' => null,
        ];
    }
}

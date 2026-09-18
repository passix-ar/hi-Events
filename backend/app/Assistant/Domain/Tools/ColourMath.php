<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

/**
 * HSL <-> hex, shared by the tools that pick page colours.
 */
final class ColourMath
{
    /** @return array{0: float, 1: float, 2: float} hue 0..1, saturation 0..1, lightness 0..1 */
    public static function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = array_map(static fn(string $c): float => hexdec($c) / 255, str_split($hex, 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h / 6, $s, $l];
    }

    public static function hslToHex(float $h, float $s, float $l): string
    {
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $channel = static function (float $t) use ($p, $q): float {
            if ($t < 0) { $t += 1; }
            if ($t > 1) { $t -= 1; }
            if ($t < 1 / 6) { return $p + ($q - $p) * 6 * $t; }
            if ($t < 1 / 2) { return $q; }
            if ($t < 2 / 3) { return $p + ($q - $p) * (2 / 3 - $t) * 6; }
            return $p;
        };

        return sprintf(
            '#%02x%02x%02x',
            (int)round($channel($h + 1 / 3) * 255),
            (int)round($channel($h) * 255),
            (int)round($channel($h - 1 / 3) * 255),
        );
    }
}

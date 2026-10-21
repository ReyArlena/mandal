<?php

namespace App\Filament;

/**
 * Catppuccin palettes for the admin panel. Accent scales put the Mocha colour at
 * 400 (dark mode text and accents) and the Latte colour at 600 (light mode text
 * and buttons). Gray is Mocha; the theme swaps in Latte for light mode.
 *
 * @see https://catppuccin.com/palette/
 */
class Catppuccin
{
    public const Red = [
        50 => 'oklch(0.975 0.009 2.764)',
        100 => 'oklch(0.946 0.022 2.764)',
        200 => 'oklch(0.904 0.042 2.764)',
        300 => 'oklch(0.841 0.077 2.764)',
        400 => 'oklch(0.756 0.130 2.764)',
        500 => 'oklch(0.612 0.190 14.696)',
        600 => 'oklch(0.550 0.216 19.809)',
        700 => 'oklch(0.487 0.187 19.809)',
        800 => 'oklch(0.434 0.156 19.809)',
        900 => 'oklch(0.391 0.124 19.809)',
        950 => 'oklch(0.270 0.081 19.809)',
    ];

    public const Green = [
        50 => 'oklch(0.975 0.007 142.715)',
        100 => 'oklch(0.960 0.018 142.715)',
        200 => 'oklch(0.937 0.035 142.715)',
        300 => 'oklch(0.903 0.065 142.715)',
        400 => 'oklch(0.858 0.109 142.715)',
        500 => 'oklch(0.695 0.157 141.126)',
        600 => 'oklch(0.625 0.177 140.445)',
        700 => 'oklch(0.545 0.154 140.445)',
        800 => 'oklch(0.477 0.128 140.445)',
        900 => 'oklch(0.424 0.102 140.445)',
        950 => 'oklch(0.270 0.067 140.445)',
    ];

    public const Peach = [
        50 => 'oklch(0.975 0.007 52.629)',
        100 => 'oklch(0.955 0.017 52.629)',
        200 => 'oklch(0.926 0.033 52.629)',
        300 => 'oklch(0.883 0.061 52.629)',
        400 => 'oklch(0.824 0.101 52.629)',
        500 => 'oklch(0.731 0.173 45.489)',
        600 => 'oklch(0.692 0.204 42.429)',
        700 => 'oklch(0.597 0.177 42.429)',
        800 => 'oklch(0.516 0.147 42.429)',
        900 => 'oklch(0.453 0.117 42.429)',
        950 => 'oklch(0.270 0.077 42.429)',
    ];

    public const Sapphire = [
        50 => 'oklch(0.975 0.007 228.653)',
        100 => 'oklch(0.951 0.016 228.653)',
        200 => 'oklch(0.916 0.031 228.653)',
        300 => 'oklch(0.862 0.058 228.653)',
        400 => 'oklch(0.791 0.096 228.653)',
        500 => 'oklch(0.691 0.104 217.618)',
        600 => 'oklch(0.648 0.107 212.889)',
        700 => 'oklch(0.562 0.093 212.889)',
        800 => 'oklch(0.490 0.077 212.889)',
        900 => 'oklch(0.433 0.061 212.889)',
        950 => 'oklch(0.270 0.040 212.889)',
    ];

    // lightened text, text, subtext1, subtext0, overlay2, overlay0, surface1, surface0, base, mantle, crust
    public const Mocha = [
        50 => 'oklch(0.939 0.022 274.737)',
        100 => 'oklch(0.879 0.043 272.277)',
        200 => 'oklch(0.817 0.040 272.862)',
        300 => 'oklch(0.751 0.040 273.932)',
        400 => 'oklch(0.687 0.037 274.725)',
        500 => 'oklch(0.550 0.034 277.095)',
        600 => 'oklch(0.404 0.032 280.152)',
        700 => 'oklch(0.324 0.032 281.978)',
        800 => 'oklch(0.243 0.030 283.911)',
        900 => 'oklch(0.216 0.025 284.065)',
        950 => 'oklch(0.183 0.020 284.204)',
    ];
}

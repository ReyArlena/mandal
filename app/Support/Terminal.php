<?php

namespace App\Support;

/**
 * Output written for a terminal, made readable in a page.
 */
final class Terminal
{
    /**
     * Drops the escape sequences programs use for colour and cursor
     * movement (`--ansi`, SteamCMD, docker), which a page would show as
     * stray `[32;1m` noise.
     */
    public static function plain(string $output): string
    {
        return (string) preg_replace([
            '/\e\[[0-?]*[ -\/]*[@-~]/',
            '/\e\][^\a\e]*(?:\a|\e\\\\)/',
            '/\e[@-Z\\\\-_]/',
        ], '', $output);
    }
}

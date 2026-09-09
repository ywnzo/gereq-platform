<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

/**
 * Přepis cid: odkazů v HTML e-mailech + sanitizace object/embed (CSP object-src 'none').
 */
final class HtmlCidRewriter
{
    /**
     * @param array<string, string> $cidToUrl map content-id (bez <>) → data:/https URL
     */
    public static function rewrite(string $html, array $cidToUrl): string
    {
        if ($html === '') {
            return '';
        }

        $map = [];
        foreach ($cidToUrl as $cid => $url) {
            $key = strtolower(trim($cid, " \t\n\r\0\x0B<>\"'"));
            if ($key !== '') {
                $map[$key] = $url;
            }
        }

        $html = (string) preg_replace_callback(
            '/([\'"])\s*cid:([^\'"\s>]+)\s*\1/i',
            static function (array $m) use ($map): string {
                $cid = strtolower(trim(rawurldecode($m[2]), "<>\"'"));
                if (isset($map[$cid])) {
                    return $m[1] . $map[$cid] . $m[1];
                }

                // 1×1 transparent GIF — zabrání CSP chybám a rozbitým ikonám
                return $m[1] . 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' . $m[1];
            },
            $html,
        );

        $html = (string) preg_replace_callback(
            '/\bsrc\s*=\s*cid:([^\s>]+)/i',
            static function (array $m) use ($map): string {
                $cid = strtolower(trim(rawurldecode(trim($m[1], "\"'<>")), "<>\"'"));
                $url = $map[$cid] ?? 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

                return 'src="' . $url . '"';
            },
            $html,
        );

        // object/embed s cid: (CSP object-src 'none')
        $html = (string) preg_replace('/<(object|embed)(\s[^>]*)?>.*?<\/\1>/is', '', $html);
        $html = (string) preg_replace('/<(object|embed)(\s[^>]*)?\/?>/is', '', $html);

        return $html;
    }

    public static function containsCid(string $html): bool
    {
        return (bool) preg_match('/\bcid:/i', $html);
    }
}

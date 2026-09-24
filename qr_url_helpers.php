<?php

if (!function_exists('vp_public_base_url')) {
    function vp_public_base_url(): string {
        $configured = trim((string)getenv('VP_PUBLIC_BASE_URL'));
        if ($configured !== '' && preg_match('#^https?://[^/]+(?:/.*)?$#i', $configured)) {
            return rtrim($configured, '/');
        }

        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $scheme = $forwardedProto !== ''
            ? strtolower(trim(explode(',', $forwardedProto)[0]))
            : ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http');
        if ($scheme !== 'https') { $scheme = 'http'; }

        $forwardedHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        $host = trim($forwardedHost !== '' ? explode(',', $forwardedHost)[0] : (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }

        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $basePath = $scriptName !== '' ? dirname($scriptName) : '';
        $basePath = str_replace('\\', '/', $basePath);
        if ($basePath === '/' || $basePath === '.' || $basePath === '\\') { $basePath = ''; }
        $basePath = '/' . trim($basePath, '/');
        if ($basePath === '/') { $basePath = ''; }

        return $scheme . '://' . $host . $basePath;
    }
}

if (!function_exists('vp_qr_link')) {
    function vp_qr_link(string $page, array $query = []): string {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return vp_public_base_url() . '/' . ltrim($page, '/') . ($queryString !== '' ? '?' . $queryString : '');
    }
}
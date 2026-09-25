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

if (!function_exists('vp_is_local_host')) {
    function vp_is_local_host(?string $host = null): bool {
        if ($host === null) {
            $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
            $parts = array_values(array_filter(array_map('trim', explode(',', $host))));
            $host = $parts ? $parts[0] : trim((string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($host === '') {
                $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
            }
        }
        if ($host === '') { return true; }
        $host = strtolower(trim($host));
        $host = preg_replace('/^(\[[0-9a-f:]+\]|[^:]+)(?::\d+)?$/', '$1', $host) ?? $host;
        $host = trim($host, '[]');
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) { return true; }
        return substr($host, -6) === '.local' || substr($host, -10) === '.localhost';
    }
}

if (!function_exists('vp_resident_qr_link')) {
    function vp_resident_qr_link(string $page, array $query = []): string {
        $base = vp_public_base_url();

        // The host the site is actually being served under during this request
        // (forwarded header first, then HTTP_HOST, then SERVER_NAME).
        $liveHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        $liveHostParts = array_values(array_filter(array_map('trim', explode(',', $liveHost))));
        $liveHost = $liveHostParts ? $liveHostParts[0] : trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($liveHost === '') {
            $liveHost = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
        }

        $baseHost = (string)parse_url($base, PHP_URL_HOST);
        if ($liveHost !== '' && !vp_is_local_host($liveHost) && vp_is_local_host($baseHost)) {
            // On a deployed site, never let a localhost fallback (stale env override,
            // CLI/proxy context, or committed dev cache) leak into the QR payload.
            $liveHostOnly = preg_replace('/^(\[[0-9a-f:]+\]|[^:]+)(?::\d+)?$/', '$1', $liveHost) ?? $liveHost;
            $scheme = 'https';
            $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($forwardedProto !== '') {
                $firstProto = strtolower(trim(explode(',', $forwardedProto)[0]));
                $scheme = $firstProto === 'https' ? 'https' : 'http';
            } elseif (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
                $scheme = 'https';
            } else {
                $scheme = 'http';
            }
            $liveScript = (string)($_SERVER['SCRIPT_NAME'] ?? '');
            $liveBasePath = $liveScript !== '' ? str_replace('\\', '/', dirname($liveScript)) : '';
            if ($liveBasePath === '/' || $liveBasePath === '.' || $liveBasePath === '\\') { $liveBasePath = ''; }
            $liveBasePath = '/' . trim($liveBasePath, '/');
            if ($liveBasePath === '/') { $liveBasePath = ''; }
            $base = $scheme . '://' . trim($liveHostOnly, '[]') . $liveBasePath;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $base . '/' . ltrim($page, '/') . ($queryString !== '' ? '?' . $queryString : '');
    }
}
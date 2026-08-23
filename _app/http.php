<?php

declare(strict_types=1);

function portal_base_path(): string {
    if (isset($_SERVER['PORTAL_BASE_PATH'])) {
        $base = (string) $_SERVER['PORTAL_BASE_PATH'];
        $base = '/' . trim(str_replace('\\', '/', $base), '/');
        return $base === '/' ? '' : rtrim($base, '/');
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');

    if (str_ends_with($base, '/_admin')) {
        $base = rtrim(str_replace('\\', '/', dirname($base)), '/');
    }

    return ($base === '' || $base === '.' || $base === '/') ? '' : $base;
}

function portal_init_base_path(): string {
    $base = portal_base_path();
    $_SERVER['PORTAL_BASE_PATH'] = $base;
    return $base;
}

function portal_path(string $path = '/'): string {
    if ($path === '') {
        $path = '/';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $path = '/' . ltrim($path, '/');
    $base = portal_base_path();

    if ($path === '/') {
        return $base === '' ? '/' : $base . '/';
    }

    return $base . $path;
}

function portal_request_path(): string {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $base = portal_base_path();

    if ($base !== '') {
        if ($path === $base) {
            return '/';
        }
        if (str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
    }

    return $path === '' ? '/' : $path;
}

function portal_origin(): string {
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $isHttps = $https === 'on' || $https === '1' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function portal_absolute_url(string $path = '/'): string {
    return portal_origin() . portal_path($path);
}

function portal_redirect(string $path, int $status = 302): never {
    header('Location: ' . portal_path($path), true, $status);
    exit;
}

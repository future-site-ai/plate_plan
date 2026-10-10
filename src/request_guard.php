<?php
/**
 * request_guard.php — Blocks requests from other websites
 *
 * Plate Plan has no login, so anyone who can reach it can use it. This guard
 * stops a website open in the user's browser from using that access:
 *  - Host check: rejects DNS-rebinding attacks (a hostile domain pointed at 127.0.0.1)
 *  - Origin / Sec-Fetch-Site check: rejects cross-site requests (CSRF, cross-origin fetch)
 *
 * Non-browser clients (curl, Claude Desktop, Cursor, other MCP clients) send
 * no Origin or Sec-Fetch-Site header and are not affected.
 *
 * Configuration (.env.local):
 *  - PLATE_PLAN_ALLOWED_HOSTS:   extra host names, comma-separated (e.g. a LAN IP), or * to allow any host
 *  - PLATE_PLAN_ALLOWED_ORIGINS: extra browser origins allowed to call the API/MCP (e.g. http://localhost:6274 for the MCP Inspector)
 */

declare(strict_types=1);

const PLATE_PLAN_DEFAULT_HOSTS = ['localhost', '127.0.0.1', '::1', 'host.docker.internal'];

function plate_plan_env_list(string $name): array
{
    $value = (string) (($_ENV[$name] ?? getenv($name)) ?: '');

    return array_values(array_filter(array_map(
        static fn (string $item): string => strtolower(rtrim(trim($item), '/')),
        explode(',', $value)
    )));
}

/** Host name without the port, e.g. "localhost:1111" → "localhost", "[::1]:1111" → "::1". */
function plate_plan_host_name(string $host): string
{
    $host = strtolower(trim($host));
    if (str_starts_with($host, '[')) {
        return substr($host, 1, (int) strpos($host, ']') - 1);
    }

    return explode(':', $host)[0];
}

function plate_plan_reject(string $reason): never
{
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Forbidden: ' . $reason]);
    exit;
}

/**
 * Call at the top of every entry point, before doing any work.
 *
 * @param bool $allowNavigation true for the HTML page: lets people open Plate Plan
 *                              by following a link (plain GET navigation only).
 */
function plate_plan_guard_request(bool $allowNavigation = false): void
{
    // 1. Host check (DNS rebinding)
    $allowedHosts = array_merge(PLATE_PLAN_DEFAULT_HOSTS, plate_plan_env_list('PLATE_PLAN_ALLOWED_HOSTS'));
    if (!in_array('*', $allowedHosts, true)) {
        $host = plate_plan_host_name($_SERVER['HTTP_HOST'] ?? '');
        if (!in_array($host, $allowedHosts, true)) {
            plate_plan_reject('unknown host. Add it to PLATE_PLAN_ALLOWED_HOSTS in .env.local.');
        }
    }

    // 2. Cross-site check
    $method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $origin   = strtolower(rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/'));
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    $fetchMode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';

    if ($allowNavigation && $method === 'GET' && $fetchMode === 'navigate') {
        return;
    }

    // Origins listed in PLATE_PLAN_ALLOWED_ORIGINS get a CORS response.
    if ($origin !== '' && in_array($origin, plate_plan_env_list('PLATE_PLAN_ALLOWED_ORIGINS'), true)) {
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id');
        header('Vary: Origin');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        return;
    }

    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
        plate_plan_reject('cross-site request.');
    }

    if ($origin !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $self   = strtolower($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
        // Behind an HTTPS reverse proxy the scheme may differ, so compare host and port only.
        $stripScheme = static fn (string $url): string => (string) preg_replace('#^[a-z]+://#', '', $url);
        if ($origin === 'null' || $stripScheme($origin) !== $stripScheme($self)) {
            plate_plan_reject('cross-origin request.');
        }
    }
}

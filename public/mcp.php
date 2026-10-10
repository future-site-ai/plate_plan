<?php
/**
 * mcp.php — Model Context Protocol (MCP) JSON-RPC 2.0 Endpoint
 *
 * Implements the standard MCP tools protocol over HTTP, exposing Plate Plan
 * meal planning, recipes, scheduling, and AI evaluation tools.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/request_guard.php';

use App\MCP\McpServer;

plate_plan_guard_request();

header('Content-Type: application/json; charset=UTF-8');

$server = new McpServer();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Helpful inspector response on GET
    echo json_encode([
        'server'       => 'plate-plan-mcp',
        'version'      => '1.0.0',
        'protocol'     => '2024-11-05',
        'capabilities' => ['tools' => true],
        'tools'        => $server->getToolsDefinitions(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$response = $server->handle($raw ?: []);

echo json_encode($response, JSON_UNESCAPED_SLASHES);

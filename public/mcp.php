<?php
/**
 * mcp.php — Model Context Protocol (MCP) JSON-RPC 2.0 Endpoint
 *
 * Implements the standard MCP tools protocol over HTTP, exposing Plate Plan
 * meal planning, recipes, scheduling, and AI evaluation tools.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';

use App\MCP\McpServer;

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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

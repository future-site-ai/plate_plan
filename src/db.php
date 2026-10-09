<?php
/**
 * db.php — SQLite3 PDO connection & auto-schema bootstrap
 *
 * Returns a reusable PDO instance with WAL journal mode and
 * foreign key enforcement. Schema is created on first load.
 */

// Load environment variables from .env.local / .env if available
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    if (class_exists(\Dotenv\Dotenv::class)) {
        $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__), ['.env.local', '.env']);
        $dotenv->safeLoad();
    }
}

if (!defined('DB_PATH')) {
    define('DB_PATH', ($_ENV['DB_PATH'] ?? getenv('DB_PATH')) ?: (dirname(__DIR__) . '/data/diet.sqlite'));
}

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0775, true);
    }

    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);

        // Performance & integrity pragmas
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');

        bootstrap_schema($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }

    return $pdo;
}

function bootstrap_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS meals (
            id   INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT    NOT NULL COLLATE NOCASE
        );

        CREATE TABLE IF NOT EXISTS ingredients (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            meal_id  INTEGER NOT NULL REFERENCES meals(id) ON DELETE CASCADE,
            name     TEXT    NOT NULL,
            quantity TEXT    NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS weekly_plan (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            day_of_week INTEGER NOT NULL CHECK(day_of_week BETWEEN 1 AND 7),
            meal_id     INTEGER NOT NULL REFERENCES meals(id) ON DELETE CASCADE,
            meal_type   TEXT    NOT NULL CHECK(meal_type IN ('breakfast','lunch','dinner','snack'))
        );
    SQL);
}

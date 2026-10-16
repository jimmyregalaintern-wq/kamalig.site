<?php
// Run this script from CLI: php scripts/import_sql.php
// It imports database_dump.sql into the configured MySQL database.

// SECURITY: CLI-only — see seed_demo_passwords.php for why this guard
// exists even with scripts/.htaccess in place.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../includes/db.php';

$sqlFile = __DIR__ . '/../database_dump.sql';
if (!file_exists($sqlFile)) {
    fwrite(STDERR, "SQL file not found: $sqlFile\n");
    exit(1);
}

/**
 * BUGFIX: splitting on ";\n" / ";$" (even after normalizing line endings)
 * treats EVERY semicolon as a statement boundary, including one that
 * appears inside a quoted string value, a quoted identifier, or a
 * comment. The current database_dump.sql happens not to contain any —
 * but the moment a future seed row's free-text field (a note, an address,
 * a company name) contains something like "Need it by 5pm; otherwise
 * cancel", this would silently cut that INSERT in half mid-string and
 * either corrupt the import or abort it entirely on a malformed-SQL
 * error that looks nothing like its real cause. This walks the file
 * character-by-character, tracking whether we're inside a single-quoted
 * string, a double-quoted string, a `backtick` identifier, a -- line
 * comment, or a /* block comment *\/, and only treats ";" as a real
 * statement boundary outside all of those. It's not a full SQL parser,
 * but it's enough to safely import a straightforward schema+seed dump
 * like this one regardless of what text ends up inside a VARCHAR/TEXT
 * value later.
 */
function split_sql_statements(string $sql): array {
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            $current .= $char;
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }
        if ($inBlockComment) {
            $current .= $char;
            if ($char === '*' && $next === '/') {
                $current .= $next;
                $i++;
                $inBlockComment = false;
            }
            continue;
        }
        if ($inSingle || $inDouble) {
            $quoteChar = $inSingle ? "'" : '"';
            $current .= $char;
            if ($char === '\\' && $next !== '') {
                // Escaped character (\', \", \\, etc.) — consume the next
                // byte verbatim so it can't be mistaken for the closing quote.
                $current .= $next;
                $i++;
                continue;
            }
            if ($char === $quoteChar) {
                if ($next === $quoteChar) {
                    // Doubled-quote escape ('' inside a '...' string).
                    $current .= $next;
                    $i++;
                } else {
                    $inSingle = false;
                    $inDouble = false;
                }
            }
            continue;
        }
        if ($inBacktick) {
            $current .= $char;
            if ($char === '`') {
                $inBacktick = false;
            }
            continue;
        }

        // Not inside any quoted/comment context right now.
        if ($char === "'") {
            $inSingle = true;
            $current .= $char;
            continue;
        }
        if ($char === '"') {
            $inDouble = true;
            $current .= $char;
            continue;
        }
        if ($char === '`') {
            $inBacktick = true;
            $current .= $char;
            continue;
        }
        if ($char === '-' && $next === '-') {
            $inLineComment = true;
            $current .= $char;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $inBlockComment = true;
            $current .= $char;
            continue;
        }
        if ($char === ';') {
            $statements[] = $current;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = $current;
    }

    return array_values(array_filter(array_map('trim', $statements), fn($s) => $s !== ''));
}

$pdo = db();
$sql = file_get_contents($sqlFile);

// database_dump.sql may have CRLF (\r\n) line endings (it's meant to be
// opened on Windows/XAMPP too); normalize first so the tokenizer's \n
// checks (inside line comments) behave the same regardless of which line
// endings the dump file uses.
$sql = str_replace("\r\n", "\n", $sql);
$stmts = split_sql_statements($sql);

$total = count($stmts);
$done = 0;
foreach ($stmts as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) continue;
    try {
        $pdo->exec($stmt);
        $done++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Import failed on statement " . ($done + 1) . " of $total:\n");
        fwrite(STDERR, substr($stmt, 0, 200) . "\n");
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Import completed: $done statement(s) executed.\n";

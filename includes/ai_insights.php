<?php
/**
 * Phase 5 — AI Functionality (see the improvement guide, section 12).
 *
 * Extends the existing "AI Supply Intelligence" panel (get_ai_supply_insights()
 * in data.php, Phase 4 — warehouse-capacity checks) with the remaining
 * guide categories: price anomaly detection, supply shortage forecasting,
 * demand forecasting, inventory anomaly detection, a document-discrepancy
 * rollup (the underlying detection already exists per-document in
 * ocr_ai.php — this just surfaces it alongside everything else), a
 * forecast-grounded procurement recommendation, and a natural-language
 * summary.
 *
 * Per the guide: "Clearly distinguish calculated KPIs from predictive or
 * AI-generated recommendations." Every insight below carries a 'kind':
 *   - 'calculated' — a fact about what already happened (an outlier price
 *     that already exists, a movement that already occurred).
 *   - 'forecast'   — a projection that assumes a recent trend continues.
 *     Inherently uncertain in a way a calculated fact isn't.
 *   - 'ai_generated' — text actually produced by a live model call
 *     (Claude), not just a rule evaluating real numbers.
 * All of it is still "deterministic checks over real numbers" in the same
 * sense get_ai_supply_insights() already documents itself as — the only
 * genuine model call in this file is get_ai_natural_language_summary().
 */

/**
 * Shared helper: every stock movement in the last $days, joined with
 * variety/unit, scoped to one account's own stock (userId given) or
 * system-wide (userId null, admin view). Built on the existing scoped
 * movement functions rather than new SQL, so both the DB and
 * session-fallback paths are inherited for free.
 */
function ai_gather_recent_movements(?string $userId, int $days): array {
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    if ($userId !== null) {
        $stocksById = array_column(get_user_stocks($userId), null, 'id');
        $movements = get_user_stock_movements($userId);
    } else {
        // System-wide: page through every movement (mirrors the
        // Transparency CSV export's loop) rather than assuming one page
        // covers everything.
        $stocksById = array_column(get_stocks(), null, 'id');
        $movements = [];
        $page = 1;
        do {
            $result = get_all_stock_movements(['dateFrom' => date('Y-m-d', strtotime("-{$days} days"))], $page, 200);
            $movements = array_merge($movements, $result['rows']);
            $page++;
        } while (count($movements) < $result['total']);
    }

    $out = [];
    foreach ($movements as $m) {
        if ($m['createdAt'] < $cutoff) {
            continue;
        }
        $stock = $stocksById[$m['stockId']] ?? null;
        $variety = $m['variety'] ?? ($stock['variety'] ?? null);
        if ($variety === null) {
            continue; // Stock no longer exists / not resolvable — skip rather than guess.
        }
        $m['variety'] = $variety;
        $m['unit'] = $m['unit'] ?? ($stock['unit'] ?? '');
        $out[] = $m;
    }
    return $out;
}

/**
 * Per-variety net movement (last 30 days) against current available
 * quantity, projected forward as a simple linear "days until zero" —
 * shared by get_variety_shortage_forecast_insights() and
 * get_procurement_recommendation_insights() so the forecast and the
 * recommendation built on top of it can never quietly disagree about the
 * underlying numbers. Returns entries sorted soonest-to-deplete first,
 * limited to varieties projected to run out within 30 days.
 */
function ai_compute_variety_shortage(?string $userId = null): array {
    $stocks = $userId !== null ? get_user_stocks($userId) : get_stocks();
    $available = array_values(array_filter($stocks, fn($s) => $s['status'] === 'available'));
    if (empty($available)) {
        return [];
    }

    $qtyByVariety = [];
    foreach ($available as $s) {
        $qtyByVariety[$s['variety']] = ($qtyByVariety[$s['variety']] ?? 0) + (float) $s['quantity'];
    }

    $netByVariety = [];
    foreach (ai_gather_recent_movements($userId, 30) as $m) {
        $netByVariety[$m['variety']] = ($netByVariety[$m['variety']] ?? 0) + (float) $m['quantityChange'];
    }

    $atRisk = [];
    foreach ($qtyByVariety as $variety => $qty) {
        $net = $netByVariety[$variety] ?? 0;
        // Negative net = net outflow over the window. Zero/positive net
        // means supply held steady or grew — nothing to project a
        // depletion date from.
        if ($net >= 0) {
            continue;
        }
        $dailyOutflow = abs($net) / 30;
        if ($dailyOutflow <= 0) {
            continue;
        }
        $daysLeft = $qty / $dailyOutflow;
        if ($daysLeft <= 30) {
            $atRisk[] = ['variety' => $variety, 'qty' => $qty, 'daysLeft' => $daysLeft, 'dailyOutflow' => $dailyOutflow];
        }
    }

    usort($atRisk, fn($a, $b) => $a['daysLeft'] <=> $b['daysLeft']);
    return $atRisk;
}

/**
 * Flags listings priced far outside the normal range for their variety —
 * mean + population standard deviation across every available listing
 * system-wide is the baseline every candidate (this account's own
 * listings if $userId is given, or every listing if admin) is judged
 * against. Needs at least 3 listings of a variety before "the market"
 * means anything; below that, any difference reads as a huge % gap.
 */
function get_price_anomaly_insights(?string $userId = null): array {
    $available = array_values(array_filter(get_stocks(), fn($s) => $s['status'] === 'available'));
    if (empty($available)) {
        return [];
    }

    $byVariety = [];
    foreach ($available as $s) {
        $byVariety[$s['variety']][] = (float) $s['unitPrice'];
    }
    $stats = [];
    foreach ($byVariety as $variety => $prices) {
        $n = count($prices);
        $mean = array_sum($prices) / $n;
        $variance = 0.0;
        foreach ($prices as $p) {
            $variance += ($p - $mean) ** 2;
        }
        $stats[$variety] = ['mean' => $mean, 'stddev' => sqrt($variance / $n), 'n' => $n];
    }

    $candidates = $userId !== null
        ? array_values(array_filter(get_user_stocks($userId), fn($s) => $s['status'] === 'available'))
        : $available;

    $flagged = [];
    foreach ($candidates as $s) {
        $stat = $stats[$s['variety']] ?? null;
        if (!$stat || $stat['n'] < 3) {
            continue;
        }
        $price = (float) $s['unitPrice'];
        $pctDiff = $stat['mean'] > 0 ? (($price - $stat['mean']) / $stat['mean']) * 100 : 0.0;
        // A stddev of 0 (every listing priced identically) makes a z-score
        // undefined — fall back to a flat 25%-of-mean threshold instead of
        // dividing by zero.
        $isAnomaly = $stat['stddev'] > 0 ? (abs(($price - $stat['mean']) / $stat['stddev']) >= 2.0) : (abs($pctDiff) >= 25);
        if ($isAnomaly) {
            $flagged[] = ['stock' => $s, 'pctDiff' => $pctDiff, 'mean' => $stat['mean']];
        }
    }

    if (empty($flagged)) {
        return [];
    }
    usort($flagged, fn($a, $b) => abs($b['pctDiff']) <=> abs($a['pctDiff']));
    $worst = $flagged[0];
    $others = count($flagged) - 1;
    $direction = $worst['pctDiff'] > 0 ? 'above' : 'below';

    return [[
        'category' => 'price_anomaly',
        'kind' => 'calculated',
        'level' => abs($worst['pctDiff']) >= 50 ? 'critical' : 'warning',
        'title' => 'Price Outlier Detected',
        'message' => ($userId !== null ? 'Your listing' : 'A listing') . ' of ' . $worst['stock']['variety'] . ' is priced at ₱'
            . number_format((float) $worst['stock']['unitPrice'], 2) . ', ' . number_format(abs($worst['pctDiff']), 0) . '% ' . $direction
            . ' the market average of ₱' . number_format($worst['mean'], 2) . ' for that variety.'
            . ($others > 0 ? ' ' . $others . ' other listing' . ($others === 1 ? '' : 's') . ' also stand out from the norm.' : '')
            . ' Double-check this isn\'t a data-entry error.',
        'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
    ]];
}

/**
 * Per-variety supply depletion forecast — distinct from
 * get_ai_supply_insights()'s per-warehouse capacity check, which reasons
 * about one physical location's capacity band. This looks at net outflow
 * trend against current available quantity for a variety regardless of
 * which warehouse it sits in. A linear projection off a 30-day average
 * daily rate, not a real time-series model — hence 'kind' => 'forecast'.
 */
function get_variety_shortage_forecast_insights(?string $userId = null): array {
    $atRisk = ai_compute_variety_shortage($userId);
    if (empty($atRisk)) {
        return [];
    }
    $worst = $atRisk[0];
    $others = count($atRisk) - 1;
    $daysLeft = (int) floor($worst['daysLeft']);

    return [[
        'category' => 'shortage_forecast',
        'kind' => 'forecast',
        'level' => $daysLeft <= 7 ? 'critical' : 'warning',
        'title' => 'Supply Trend Indicator',
        'message' => 'At the current 30-day consumption pace, ' . $worst['variety'] . ' (' . number_format($worst['qty'], 0)
            . ' remaining) is projected to run out in about ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . '.'
            . ($others > 0 ? ' ' . $others . ' other variet' . ($others === 1 ? 'y is' : 'ies are') . ' also trending toward depletion within 30 days.' : ''),
        'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
    ]];
}

/**
 * Per-variety demand trend from procurement order volume, from the
 * selling side (orders where this account — or, system-wide, anyone — is
 * the supplier). Compares the last 30 days against the prior 30 and
 * linearly projects the next 30 as "last period plus the same
 * period-over-period change again" — a trend continuation, not a
 * seasonal or ML forecast, hence 'kind' => 'forecast'. Cancelled orders
 * never actually consumed supply, so they're excluded.
 */
function get_demand_forecast_insights(?string $userId = null): array {
    $orders = $userId !== null ? get_user_procurement_orders($userId) : get_procurement_orders();
    $orders = array_values(array_filter($orders, function ($o) use ($userId) {
        if ($o['status'] === 'cancelled') {
            return false;
        }
        return $userId === null || $o['supplierId'] === $userId;
    }));
    if (count($orders) < 4) {
        return [];
    }

    $now = time();
    $recent = 0.0;
    $prior = 0.0;
    $byVariety = [];
    foreach ($orders as $o) {
        $age = ($now - strtotime($o['createdAt'])) / 86400;
        if ($age <= 30) {
            $recent += (float) $o['quantity'];
            $byVariety[$o['variety']] = ($byVariety[$o['variety']] ?? 0) + (float) $o['quantity'];
        } elseif ($age <= 60) {
            $prior += (float) $o['quantity'];
        }
    }
    if ($recent <= 0) {
        return [];
    }
    arsort($byVariety);
    $topVariety = array_key_first($byVariety) ?? 'rice';

    if ($prior <= 0) {
        // No prior-period baseline to compare against — report the
        // current volume as a starting point rather than a meaningless
        // "infinite % increase".
        return [[
            'category' => 'demand_forecast',
            'kind' => 'forecast',
            'level' => 'info',
            'title' => 'Demand Snapshot',
            'message' => number_format($recent, 0) . ' units ordered in the last 30 days, led by ' . $topVariety
                . ', with no prior-30-day period yet to compare against.',
            'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
        ]];
    }

    $changePct = (($recent - $prior) / $prior) * 100;
    if (abs($changePct) < 15) {
        return []; // Not a strong enough trend to call out.
    }
    $projectedNext = max(0, $recent + ($recent - $prior));

    return [[
        'category' => 'demand_forecast',
        'kind' => 'forecast',
        'level' => $changePct > 0 ? 'recommendation' : 'info',
        'title' => 'Demand ' . ($changePct > 0 ? 'Rising' : 'Falling'),
        'message' => 'Order volume ' . ($changePct > 0 ? 'rose' : 'fell') . ' ' . number_format(abs($changePct), 0)
            . '% over the last 30 days (' . number_format($prior, 0) . ' → ' . number_format($recent, 0) . ' units, led by ' . $topVariety . '). '
            . 'If this trend continues, the next 30 days may see roughly ' . number_format($projectedNext, 0) . ' units of demand.',
        'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
    ]];
}

/**
 * Flags an individual stock-in or stock-out whose size is far outside
 * this account's (or, system-wide, everyone's) typical movement size for
 * that type over the last 30 days — a single movement several times
 * larger than every other one recently is more likely to be a data-entry
 * slip (an extra zero) than a real event, and worth a second look.
 * Adjustments are corrections by definition and transfers don't change
 * total quantity held, so neither is a meaningful "unusually large?"
 * question the way in/out volume is.
 */
function get_inventory_anomaly_insights(?string $userId = null): array {
    $sized = array_values(array_filter(
        ai_gather_recent_movements($userId, 30),
        fn($m) => in_array($m['type'], ['in', 'out'], true)
    ));
    if (count($sized) < 5) {
        return [];
    }

    $byType = ['in' => [], 'out' => []];
    foreach ($sized as $m) {
        $byType[$m['type']][] = $m;
    }

    $flagged = [];
    foreach ($byType as $type => $group) {
        if (count($group) < 5) {
            continue;
        }
        $sizes = array_map(fn($m) => abs((float) $m['quantityChange']), $group);
        $mean = array_sum($sizes) / count($sizes);
        if ($mean <= 0) {
            continue;
        }
        foreach ($group as $i => $m) {
            // 4x the average movement size for this type is the anomaly
            // line — deliberately high (rather than a stddev z-score),
            // since movement sizes are typically right-skewed (many small
            // restocks, occasional bulk ones), so a normal large batch
            // shouldn't trip this as often as a real outlier would.
            if ($sizes[$i] >= $mean * 4) {
                $flagged[] = ['movement' => $m, 'size' => $sizes[$i], 'mean' => $mean, 'type' => $type];
            }
        }
    }

    if (empty($flagged)) {
        return [];
    }
    usort($flagged, fn($a, $b) => $b['size'] <=> $a['size']);
    $worst = $flagged[0];
    $others = count($flagged) - 1;

    return [[
        'category' => 'inventory_anomaly',
        'kind' => 'calculated',
        'level' => 'warning',
        'title' => 'Unusual Stock Movement',
        'message' => 'A ' . ($worst['type'] === 'in' ? 'stock-in' : 'stock-out') . ' of ' . number_format($worst['size'], 0) . ' '
            . $worst['movement']['unit'] . ' of ' . $worst['movement']['variety'] . ' on ' . date('M j', strtotime($worst['movement']['createdAt']))
            . ' is about ' . number_format($worst['size'] / $worst['mean'], 1) . '× the typical ' . $worst['type'] . ' size ('
            . number_format($worst['mean'], 0) . ') over the last 30 days.'
            . ($others > 0 ? ' ' . $others . ' other movement' . ($others === 1 ? '' : 's') . ' also stand out.' : '')
            . ' Worth a quick double-check.',
        'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
    ]];
}

/**
 * Rolls up AI-flagged document mismatches (already detected per-document
 * by includes/ocr_ai.php's OCR + Claude validation pipeline, stored on
 * documents.aiFlag/aiFlagReason) into the same insights feed as every
 * other AI signal — otherwise a flagged document is only visible to
 * someone who happens to open Admin > Compliance (or, for its owner,
 * their own Documents page).
 */
function get_document_discrepancy_insights(?string $userId = null): array {
    $docs = $userId !== null ? get_documents($userId) : get_pending_documents();
    $flagged = array_values(array_filter($docs, fn($d) => !empty($d['aiFlag']) && $d['status'] === 'pending'));
    if (empty($flagged)) {
        return [];
    }

    $count = count($flagged);
    $worst = $flagged[0];
    $subject = $userId !== null
        ? ($count === 1 ? 'One of your pending documents was' : $count . ' of your pending documents were')
        : ($count === 1 ? 'One pending document was' : $count . ' pending documents were');

    return [[
        'category' => 'document_discrepancy',
        'kind' => 'ai_generated',
        'level' => 'warning',
        'title' => 'Document Discrepancy Flagged',
        'message' => $subject . ' flagged during automated review' . ($worst['aiFlagReason'] ? (': ' . $worst['aiFlagReason']) : '.')
            . ($userId === null ? ' Review flagged items in Admin > Compliance.' : ''),
        'confidence' => $worst['aiMatchScore'] !== null ? (int) $worst['aiMatchScore'] : null,
    ]];
}

/**
 * A reorder recommendation grounded in ai_compute_variety_shortage()'s
 * forecast — distinct from get_ai_supply_insights()'s existing
 * "Procurement Recommendation" (which only reasons about restocking a
 * warehouse back to a healthy capacity band). This one only fires when
 * there's an actual projected depletion to act on, and reuses the exact
 * same computation the shortage-forecast insight above is built from, so
 * the two can never quietly disagree with each other.
 */
function get_procurement_recommendation_insights(?string $userId = null): array {
    $atRisk = ai_compute_variety_shortage($userId);
    if (empty($atRisk)) {
        return [];
    }
    $worst = $atRisk[0];
    $recommendedQty = round($worst['dailyOutflow'] * 30);

    return [[
        'category' => 'procurement_recommendation',
        'kind' => 'forecast',
        'level' => 'recommendation',
        'title' => 'Reorder Recommendation',
        'message' => 'Based on the ' . $worst['variety'] . ' supply trend indicator, procuring roughly ' . number_format($recommendedQty, 0)
            . ' units would cover about 30 more days at the current consumption pace.',
        'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
    ]];
}

/**
 * Every AI-surfaced signal for the dashboard's AI panel — the original
 * warehouse-capacity insights (get_ai_supply_insights(), Phase 4) plus
 * every new Phase 5 category above, each tagged with 'category' and
 * 'kind' so the UI can group them and clearly distinguish calculated
 * facts from forward-looking forecasts from actual AI-generated text
 * (the guide's explicit instruction in section 12).
 */
function get_extended_ai_insights(?string $userId = null): array {
    $capacity = array_map(function ($insight) {
        $insight['category'] = $insight['category'] ?? 'capacity';
        $insight['kind'] = $insight['kind'] ?? 'calculated';
        return $insight;
    }, get_ai_supply_insights($userId));

    return array_merge(
        $capacity,
        get_price_anomaly_insights($userId),
        get_variety_shortage_forecast_insights($userId),
        get_demand_forecast_insights($userId),
        get_inventory_anomaly_insights($userId),
        get_document_discrepancy_insights($userId),
        get_procurement_recommendation_insights($userId)
    );
}

/**
 * A short prose synthesis of every insight gathered this call — the
 * guide's "Natural-language explanations" item. Uses the same Anthropic
 * call pattern as includes/ocr_ai.php's document validation
 * (http_post_json(), same auth headers) when ANTHROPIC_API_KEY is
 * configured; otherwise falls back to a deterministic template built from
 * the same insight titles/messages, so the panel always shows something
 * rather than an empty section on an unconfigured install. The result
 * carries 'source' so the UI can label which one actually ran — the guide
 * is explicit that AI-generated text must read as distinct from
 * calculated/rule-based content, not blended in unlabeled.
 */
function get_ai_natural_language_summary(array $insights, ?string $userId = null): array {
    if (empty($insights)) {
        return [
            'text' => 'No notable supply signals to report right now.',
            'source' => 'template',
            'generatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    $top = array_slice($insights, 0, 3);
    $parts = array_map(fn($i) => rtrim($i['message'], '.'), $top);
    $template = 'In summary: ' . implode('; ', $parts) . '.';

    if (!ai_validation_is_configured()) {
        return ['text' => $template, 'source' => 'template', 'generatedAt' => date('Y-m-d H:i:s')];
    }

    // Same insights for the same person => same summary: reuse it for 6 hours instead of
    // paying for a model call on every dashboard view. A change in any insight changes the key.
    $cacheKey = 'summary|' . ($userId ?? 'admin') . '|' . hash('sha256', json_encode(array_map(fn($i) => [$i['level'], $i['title'], $i['message']], $insights)));
    $cached = ai_cache_get($cacheKey, 6 * 3600);
    if ($cached !== null && !empty($cached['text'])) {
        return $cached;
    }

    try {
        $bulleted = implode("\n", array_map(
            fn($i) => '- [' . strtoupper($i['level']) . '] ' . $i['title'] . ': ' . $i['message'],
            $insights
        ));
        $prompt = 'You are writing a short (2-4 sentence) natural-language summary of the following supply-chain '
            . 'insights for a rice trading platform, for a ' . ($userId !== null ? 'farmer/supplier/importer account' : 'platform administrator') . '. '
            . "Be concrete, reference the actual numbers already given, and do not invent any figure that isn't in the "
            . "list below. Plain prose only, no markdown, no bullet points, no preamble.\n\n" . $bulleted;

        $response = anthropic_request([
            'max_tokens' => 220,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], 'summary', $userId, 15);
        $text = trim($response['content'][0]['text'] ?? '');
        if ($text === '') {
            throw new RuntimeException('Empty AI summary response.');
        }
        // The prompt forbids invented figures; this CHECKS it. Any number of two or more
        // digits (or with a decimal / percent sign) that is not in the source insights
        // means the text is not trusted and the plain template is shown instead.
        if (!ai_numbers_grounded($text, $bulleted)) {
            error_log('AI summary rejected: it contained a figure that is not in the insights.');
            return ['text' => $template, 'source' => 'template', 'generatedAt' => date('Y-m-d H:i:s')];
        }
        $result = ['text' => $text, 'source' => 'ai', 'generatedAt' => date('Y-m-d H:i:s')];
        ai_cache_put($cacheKey, $result);
        return $result;
    } catch (AiUnavailableException $e) {
        // Paused, over the daily cap, or no key: not an error worth logging per page view.
        return ['text' => $template, 'source' => 'template', 'generatedAt' => date('Y-m-d H:i:s')];
    } catch (Throwable $e) {
        error_log('AI natural-language summary failed: ' . $e->getMessage());
        return ['text' => $template, 'source' => 'template', 'generatedAt' => date('Y-m-d H:i:s')];
    }
}

/**
 * True when every significant number in $text also appears in $source.
 * "Significant" = two or more digits, or any figure with a decimal point or percent sign.
 * Thousands separators are ignored, so 1,200 matches 1200.
 */
function ai_numbers_grounded(string $text, string $source): bool {
    $normalize = function (string $n): string {
        $n = str_replace(',', '', $n);
        if (strpos($n, '.') !== false) {
            $n = rtrim(rtrim($n, '0'), '.'); // 12.50 == 12.5, 3.00 == 3
        }
        return $n === '' ? '0' : $n;
    };
    preg_match_all('/\d[\d,]*(?:\.\d+)?%?/', $source, $m);
    $allowed = [];
    foreach ($m[0] as $n) {
        $allowed[$normalize(rtrim($n, '%'))] = true;
    }
    preg_match_all('/\d[\d,]*(?:\.\d+)?%?/', $text, $t);
    foreach ($t[0] as $n) {
        $bare = rtrim($n, '%');
        $digits = preg_replace('/\D/', '', $bare);
        $significant = strlen($digits) >= 2 || strpos($bare, '.') !== false || substr($n, -1) === '%';
        if ($significant && !isset($allowed[$normalize($bare)])) {
            return false;
        }
    }
    return true;
}

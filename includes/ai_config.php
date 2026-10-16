<?php
/**
 * OCR / AI Configuration for KAMALIG
 *
 * Both integrations are OPTIONAL — if a key is missing, the pipeline in
 * includes/ocr_ai.php skips that step and marks the document accordingly
 * (ocrStatus = 'not_configured') instead of failing the upload.
 *
 * SECURITY: set these via real environment variables (XAMPP: system env
 * vars on Windows, or a .env loaded before Apache starts; on a real host:
 * your hosting panel's environment variable settings). Do NOT hardcode a
 * real key as the getenv() fallback here — that's how a live key ends up
 * committed to source control.
 *
 * --- Google Cloud Vision (OCR) ---
 * 1. Create/select a project at https://console.cloud.google.com
 * 2. Enable the "Cloud Vision API"
 * 3. Create an API key (APIs & Services > Credentials) and restrict it to
 *    the Vision API only
 * 4. Set GOOGLE_VISION_API_KEY
 *
 * --- Anthropic Claude (AI validation) ---
 * 1. Get an API key from https://console.anthropic.com
 * 2. Set ANTHROPIC_API_KEY
 */

if (!defined('GOOGLE_VISION_API_KEY')) define('GOOGLE_VISION_API_KEY', getenv('GOOGLE_VISION_API_KEY') ?: '');
if (!defined('ANTHROPIC_API_KEY')) define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
if (!defined('ANTHROPIC_MODEL')) define('ANTHROPIC_MODEL', getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001');

function ocr_is_configured(): bool {
    return GOOGLE_VISION_API_KEY !== '';
}

function ai_validation_is_configured(): bool {
    return ANTHROPIC_API_KEY !== '';
}

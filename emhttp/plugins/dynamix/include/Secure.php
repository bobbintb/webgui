<?PHP
/* Copyright 2005-2025, Lime Technology
 * Copyright 2012-2025, Bergware International.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

/**
 * Remove malicious HTML elements and decode entities.
 *
 * @param string|null $text
 * @return string
 */
function untangle($text) {
  return strip_tags(html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Remove malicious code appended after variable assignment.
 * Splits by common shell/URL delimiters.
 *
 * @param string|null $text
 * @return string
 */
function unscript($text) {
  $clean = untangle($text);
  $parts = preg_split('/[;|&\?=]/', $clean);
  return trim($parts[0] ?? '');
}

/**
 * Remove malicious code appended after string variable.
 * Removes quotes and trailing content, and strips dangerous characters.
 *
 * @param string|null $text
 * @return string
 */
function unbundle($text) {
  $decoded = html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
  // Remove content after quotes
  $removed_quotes = preg_replace("#['\"](.*?)['\"];?.+$#", '', $decoded);
  // Strip dangerous characters: ( ) [ ] / \ & `
  $stripped = preg_replace("#[()\[\]/\\\\&`]#", '', $removed_quotes);
  // Split by delimiters
  $parts = preg_split('/[;|\?=]/', $stripped);
  return trim($parts[0] ?? '');
}
?>

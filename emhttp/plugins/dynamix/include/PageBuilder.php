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

require_once "$docroot/webGui/include/MarkdownExtra.inc.php";
require_once "$docroot/webGui/include/Wrappers.php";

/**
 * Get a value from an ini-style variable.
 * Note: Still uses eval for complex keys, but wrapped in safety.
 */
function get_ini_key(string $key, $default) {
  if (strpos($key, '$') !== 0) return $default;
  $x = strpos($key, '[');
  $var_name = ($x > 0) ? substr($key, 1, $x - 1) : substr($key, 1);

  global $$var_name;
  if (!isset($$var_name)) return $default;

  try {
    // Basic validation to avoid arbitrary code execution
    if (preg_match('/^\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(\[[\'"]?[a-zA-Z0-9_\x7f-\xff]+[\'"]?\])*$/', $key)) {
      $val = null;
      @eval("\$val = $key;");
      return $val ?? $default;
    }
  } catch (Throwable $e) {
    // Fallback to default on error
  }
  return $default;
}

/**
 * Get a value from an ini file.
 */
function get_file_key(string $file, string $default) {
  [$key, $def_val] = my_explode('=', $default);
  $ini = @parse_ini_file($file);
  return $ini[$key] ?? $def_val;
}

/**
 * Scan a directory for .page files and build the site structure.
 */
function build_pages(string $pattern): void {
  global $site;
  $files = glob($pattern, GLOB_NOSORT);
  if ($files === false) return;

  foreach ($files as $entry) {
    $content = @file_get_contents($entry);
    if ($content === false) continue;

    [$header, $text] = my_explode("\n---\n", $content);
    $page = @parse_ini_string($header);
    if (!$page) {
      my_logger("Invalid .page format: $entry");
      continue;
    }

    $page['file'] = $entry;
    $page['root'] = dirname($entry);
    $page['name'] = basename($entry, '.page');
    $page['text'] = $text;
    $site[$page['name']] = $page;
  }
}

/**
 * Check if a page is enabled based on its Cond attribute.
 */
function page_enabled(array &$page): bool {
  global $docroot, $var, $disks, $devs, $users, $shares, $sec, $sec_nfs, $name, $display, $pool_devices;
  if (!isset($page['Cond'])) return true;

  $enabled = true;
  $evalSuccess = true;
  $evalContent = "\$enabled = {$page['Cond']};";
  $evalFile = $page['file'];

  // Using include for evalContent.php as per original design for variable scope access
  $eval_path = "$docroot/webGui/include/DefaultPageLayout/evalContent.php";
  if (is_file($eval_path)) {
    include $eval_path;
  } else {
    // Fallback if file missing
    try {
      @eval($evalContent);
    } catch (Throwable $e) {
      $evalSuccess = false;
    }
  }

  return ($enabled && $evalSuccess);
}

/**
 * Find pages that belong to a specific menu item.
 */
function find_pages(string $item): array {
  global $site;
  $pages = [];
  foreach (($site ?? []) as $page) {
    if (empty($page['Menu'])) continue;

    $menu_str = $page['Menu'];
    $first_word = strtok($menu_str, ' ');
    $menu_id = $first_word;

    if ($first_word[0] === '$') {
      $menu_id = get_ini_key($first_word, strtok(' '));
    } elseif ($first_word[0] === '/') {
      $menu_id = get_file_key($first_word, strtok(' '));
    }

    while ($menu_id !== false) {
      [$m, $rank] = my_explode(':', (string)$menu_id);
      if ($m === $item) {
        if (page_enabled($page)) {
          $pages["$rank{$page['name']}"] = $page;
        }
        break;
      }
      $menu_id = strtok(' ');
    }
  }
  ksort($pages, SORT_NATURAL);
  return $pages;
}

/**
 * Generate HTML for a tab title.
 */
function tab_title(string $title, string $path, ?string $tag): string {
  global $docroot, $pools;
  $title = htmlspecialchars(html_entity_decode($title));

  $assigned_pools = $pools ?? [];
  $device_names = implode('|', array_merge(['disk', 'parity'], $assigned_pools));

  if (preg_match("/^($device_names)/", $title)) {
    $device = strtok($title, ' ');
    $translated_disk = _(my_disk($device), 3);
    $title = str_replace($device, $translated_disk, $title);
  }

  // parse_text is assumed to be defined globally
  if (function_exists('parse_text')) {
    $title = _(parse_text($title));
  } else {
    $title = _($title);
  }

  $wrapperClasses = 'left inline-flex flex-row items-center gap-1';

  if (!$tag || substr($tag, -4) === '.png') {
    $icon_name = $tag ?: strtolower(str_replace(' ', '', $title)) . ".png";
    $icon_path = "$path/icons/$icon_name";
    if (is_file("$docroot/$icon_path")) {
      return "<span class='$wrapperClasses'><img src='/$icon_path' class='icon' style='max-width: 18px; max-height: 18px; width: auto; height: auto; object-fit: contain;'>$title</span>";
    }
    return "<span class='$wrapperClasses'><i class='fa fa-th title'></i>$title</span>";
  }

  if (strpos($tag, 'icon-') === 0) {
    return "<span class='$wrapperClasses'><i class='$tag title'></i>$title</span>";
  }

  $fa_tag = (strpos($tag, 'fa-') === 0) ? $tag : "fa-$tag";
  return "<span class='$wrapperClasses'><i class='fa $fa_tag title'></i>$title</span>";
}

/**
 * Generate CSS for sidebar icons.
 */
function generate_sidebar_icon_css(array $tasks, array $buttons): string {
  $css = '';
  foreach ($tasks as $page) {
    if (isset($page['Code'])) {
      $css .= ".nav-item a[href='/{$page['name']}']:before{content:'\\" . htmlspecialchars($page['Code']) . "'}\n";
    }
  }
  $css .= ".nav-item.LockButton a:before{content:'\\e955'}\n";
  foreach ($buttons as $page) {
    if (isset($page['Code'])) {
      $css .= ".nav-item.{$page['name']} a:before{content:'\\" . htmlspecialchars($page['Code']) . "'}\n";
    }
  }
  return $css;
}

/**
 * Include stylesheets for a page.
 */
function includePageStylesheets(array $page): void {
  global $docroot, $theme;
  $base_path = "/{$page['root']}/sheets/{$page['name']}";

  $files = ["$base_path.css"];
  if ($theme) $files[] = "{$base_path}-{$theme}.css";

  foreach ($files as $f) {
    if (is_file($docroot . $f)) {
      echo '<link type="text/css" rel="stylesheet" href="', autov($f), '">', "\n";
    }
  }
}

/**
 * Output an HTML comment for debugging.
 */
function annotate(string $text): void {
  $text = htmlspecialchars($text);
  $line = str_repeat("#", strlen($text));
  echo "\n<!--\n$line\n$text\n$line\n-->\n";
}

// Helper to embed function output in strings.
function _func($x) { return $x; }
$func = '_func';
?>

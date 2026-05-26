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

$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/webGui/include/Wrappers.php";
require_once "$docroot/webGui/include/Secure.php";

/**
 * Scale a value and append appropriate unit.
 *
 * @param float|int $value
 * @param string $unit Returned unit
 * @param int|null $decimals
 * @param int|null $scale
 * @param int $kilo
 * @return string Formatted number
 */
function my_scale($value, &$unit, $decimals = null, $scale = null, $kilo = 1000): string {
  global $display, $language;
  $scale ??= $display['scale'] ?? 0;
  $number = _var($display, 'number', '.,');
  $prefix_key = ($kilo == 1000) ? 'prefix_SI' : 'prefix_IEC';
  $units_str = $language[$prefix_key] ?? ($kilo == 1000 ? 'K M G T P E Z Y' : 'Ki Mi Gi Ti Pi Ei Zi Yi');
  $units = explode(' ', ' ' . $units_str);
  $size = count($units);

  if ($scale == 0 && ($decimals === null || $decimals < 0)) {
    $decimals = 0;
    $unit = '';
  } else {
    $base = $value ? (int)floor(log($value, $kilo)) : 0;
    if ($scale > 0 && $base > $scale) $base = $scale;
    if ($base >= $size) $base = $size - 1;
    $value /= pow($kilo, $base);
    if ($decimals === null) {
      $decimals = ($value >= 100) ? 0 : (($value >= 10) ? 1 : (round($value * 100) % 100 === 0 ? 0 : 2));
    } elseif ($decimals < 0) {
      $decimals = ($value >= 100 || round($value * 10) % 10 === 0) ? 0 : abs($decimals);
    }
    if ($scale < 0 && round($value, -1) == 1000) {
      $value = 1;
      $base++;
    }
    $unit = $units[$base] . _('B');
  }
  return number_format($value, $decimals, $number[0], $value > 9999 ? $number[1] : '');
}

/**
 * Format a number according to display settings.
 */
function my_number($value): string {
  global $display;
  $number = _var($display, 'number', '.,');
  return number_format($value, 0, $number[0], ($value >= 10000 ? $number[1] : ''));
}

/**
 * Format time according to display settings.
 */
function my_time($time, $fmt = null): string {
  global $display;
  if (!$fmt) {
    $date_fmt = _var($display, 'date');
    $time_fmt = _var($display, 'time');
    $fmt = $date_fmt . ($date_fmt !== '%c' ? ", " . $time_fmt : "");
  }
  return $time ? my_date($fmt, $time) : _('unknown');
}

/**
 * Format temperature.
 */
function my_temp($value): string {
  global $display;
  $unit = _var($display, 'unit', 'C');
  $number = _var($display, 'number', '.,');
  if (!is_numeric($value)) return (string)$value;
  $formatted = ($unit === 'F') ? fahrenheit($value) : str_replace('.', $number[0], (string)$value);
  return $formatted . '&#8201;&#176;' . $unit;
}

/**
 * Format disk name.
 */
function my_disk($name, $raw = false): string {
  global $display;
  if (_var($display, 'raw') || $raw) return (string)$name;
  return ucfirst(preg_replace('/(\d+)$/', ' $1', (string)$name));
}

/**
 * Check if disk is present.
 */
function my_disks($disk): bool {
  return strpos(_var($disk, 'status'), '_NP') === false;
}

/**
 * Replace [text](link) style brackets with hyperlinks.
 */
function my_hyperlink(string $text, string $link): string {
  return str_replace(['[', ']'], ["<a href=\"$link\">", "</a>"], $text);
}

// Disk type filters
function main_only($disk): bool { return in_array(_var($disk, 'type'), ['Parity', 'Data']); }
function parity_only($disk): bool { return _var($disk, 'type') === 'Parity'; }
function data_only($disk): bool { return _var($disk, 'type') === 'Data'; }
function cache_only($disk): bool { return _var($disk, 'type') === 'Cache'; }
function boot_only($disk): bool { return _var($disk, 'type') === 'Boot'; }
function luks_only($disk): bool { return in_array(_var($disk, 'type'), ['Data', 'Cache']); }

function main_filter(array $disks): array { return array_filter($disks, 'main_only'); }
function parity_filter(array $disks): array { return array_filter($disks, 'parity_only'); }
function data_filter(array $disks): array { return array_filter($disks, 'data_only'); }
function cache_filter(array $disks): array { return array_filter($disks, 'cache_only'); }
function boot_filter(array $disks): array { return array_filter($disks, 'boot_only'); }
function luks_filter(array $disks): array { return array_filter($disks, 'luks_only'); }

function pools_filter(array $disks): array {
  $cache_pools = array_keys(cache_filter($disks));
  return array_unique(array_map('prefix', $cache_pools));
}

function flash_filter(array $disks): array {
  $boot_pools = array_keys(boot_filter($disks));
  return array_unique(array_map('prefix', $boot_pools));
}

/**
 * Strip WWN prefix from ID if configured.
 */
function my_id($id): string {
  global $display;
  $id = (string)$id;
  $len = strlen($id);
  $wwn = substr($id, -18);
  if (_var($display, 'wwn') || substr($wwn, 0, 2) !== '_3' || preg_match('/.[_-]/', $wwn)) return $id;
  return substr($id, 0, $len - 18);
}

/**
 * Convert number to word representation.
 */
function my_word($num) {
  $words = ['zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen','twenty','twenty-one','twenty-two','twenty-three','twenty-four','twenty-five','twenty-six','twenty-seven','twenty-eight','twenty-nine','thirty'];
  return ($num >= 0 && $num < count($words)) ? _($words[$num], 1) : $num;
}

/**
 * Display usage bar for the array.
 */
function my_usage(): void {
  global $disks, $var, $display;
  $arraysize = 0;
  $arrayfree = 0;
  foreach (($disks ?? []) as $disk) {
    if (strpos(_var($disk, 'name'), 'disk') !== false) {
      $arraysize += _var($disk, 'sizeSb', 0);
      $arrayfree += _var($disk, 'fsFree', 0);
    }
  }
  if (_var($var, 'fsNumMounted', 0) > 0) {
    $used = $arraysize ? 100 - round(100 * $arrayfree / $arraysize) : 0;
    echo "<div class='usage-bar'><span style='width:{$used}%' class='" . usage_color($display, $used, false) . "'>{$used}%</span></div>";
  } else {
    $status = (($var['fsState'] ?? '') === 'Started') ? 'Maintenance' : 'offline';
    echo "<div class='usage-bar'><span style='text-align:center'>" . _($status) . "</span></div>";
  }
}

/**
 * Get CSS class for usage bar color.
 */
function usage_color(&$disk, $limit, $free): string {
  global $display;
  $text_mode = (int)_var($display, 'text', 0);
  if ($text_mode === 1 || intval($text_mode / 10) === 1) return '';

  $critical = (int)_var($disk, 'critical', _var($display, 'critical', 0));
  $warning = (int)_var($disk, 'warning', _var($display, 'warning', 0));

  if (!$free) {
    if ($critical > 0 && $limit >= $critical) return 'redbar';
    if ($warning > 0 && $limit >= $warning) return 'orangebar';
    return 'greenbar';
  } else {
    if ($critical > 0 && $limit <= 100 - $critical) return 'redbar';
    if ($warning > 0 && $limit <= 100 - $warning) return 'orangebar';
    return 'greenbar';
  }
}

/**
 * Format parity check results.
 */
function my_check($time, $speed): string {
  if (!$time) return _('unavailable (no parity-check entries logged)');
  $days = floor($time / 86400);
  $hmss = $time - $days * 86400;
  $hour = floor($hmss / 3600);
  $mins = floor($hmss / 60) % 60;
  $secs = $hmss % 60;
  $speed_str = is_numeric($speed) ? my_scale($speed, $unit, 1) . " $unit/s" : $speed;
  return plus($days, 'day', ($hour|$mins|$secs) == 0) .
         plus($hour, 'hour', ($mins|$secs) == 0) .
         plus($mins, 'minute', $secs == 0) .
         plus($secs, 'second', true) . ". " . _('Average speed') . ": " . $speed_str;
}

/**
 * Format error codes.
 */
function my_error($code): string {
  return ($code == -4) ? "<em>" . _('aborted') . "</em>" : "<strong>" . htmlspecialchars((string)$code) . "</strong>";
}

/**
 * Generate HTML option tag.
 */
function mk_option($select, $value, $text, $extra = ""): string {
  $selected = ($value == $select) ? " selected" : "";
  $extra = $extra ? " $extra" : "";
  return "<option value='" . htmlspecialchars((string)$value, ENT_QUOTES) . "'$selected$extra>" . htmlspecialchars((string)$text) . "</option>";
}

/**
 * Generate HTML option tag for disk selection.
 */
function mk_option_check($name, $value, $text = ""): string {
  $name = (string)$name;
  $value = (string)$value;
  if ($text !== "") {
    $checked = in_array($value, explode(',', $name)) ? " selected" : "";
    return "<option value='" . htmlspecialchars($value, ENT_QUOTES) . "'$checked>" . htmlspecialchars((string)$text) . "</option>";
  }
  if (strpos($name, 'disk') !== false) {
    $checked = in_array($name, explode(',', $value)) ? " selected" : "";
    return "<option value='" . htmlspecialchars($name, ENT_QUOTES) . "'$checked>" . htmlspecialchars(my_disk($name)) . "</option>";
  }
  return "";
}

function mk_option_luks($name, $value, $luks): string {
  $name = (string)$name;
  $value = (string)$value;
  if (strpos($name, 'disk') !== false) {
    $checked = in_array($name, explode(',', $value)) ? " selected" : "";
    return "<option luks='" . htmlspecialchars((string)$luks, ENT_QUOTES) . "' value='" . htmlspecialchars($name, ENT_QUOTES) . "'$checked>" . htmlspecialchars(my_disk($name)) . "</option>";
  }
  return "";
}

/**
 * Format relative day count.
 */
function day_count($time): ?string {
  global $var;
  if (!$time) return null;
  try {
    $datetz = new DateTimeZone($var['timeZone'] ?? 'UTC');
    $now = new DateTime("now", $datetz);
    $offset = $datetz->getOffset($now);
    $today_midnight = (int)( (time() + $offset) / 86400 ) * 86400;
    $last_midnight  = (int)( ($time + $offset) / 86400 ) * 86400;
    $days = (int)( ($today_midnight - $last_midnight) / 86400 );
  } catch (Exception $e) {
    return null;
  }

  if ($days < 0) return null;
  if ($days == 0) return " <span class='green-text'>(" . _('today') . ")</span>";
  if ($days == 1) return " <span class='green-text'>(" . _('yesterday') . ")</span>";

  $color = ($days <= 31) ? 'green' : (($days <= 61) ? 'orange' : 'red');
  $word = ($days <= 31) ? my_word($days) : $days;
  return " <span class='{$color}-text'>(" . sprintf(_('%s days ago'), $word) . ")</span>";
}

/**
 * Helper for pluralizing time units.
 */
function plus($val, $word, $last): string {
  if ($val <= 0) return '';
  $unit = _($word . ($val != 1 ? 's' : ''));
  return $val . ' ' . $unit . ($last ? '' : ', ');
}

/**
 * Compress long strings by adding ellipsis.
 */
function compress(string $name, int $size = 18, int $end = 6): string {
  if (mb_strlen($name) <= $size) return $name;
  return mb_substr($name, 0, $size - ($end ? $end + 3 : 3)) . '...' . ($end ? mb_substr($name, -$end) : '');
}

function escapestring(string $name): string {
  return "\"$name\"";
}

/**
 * Read the last N lines of a file.
 */
function tail(string $file, int $rows = 1): string {
  if (!is_file($file)) return "";
  try {
    $f = new SplFileObject($file);
    $f->seek(PHP_INT_MAX);
    $total_rows = $f->key();
    $start = max(0, $total_rows - $rows);
    $f->seek($start);
    $lines = [];
    while (!$f->eof()) {
      $line = $f->current();
      if ($line !== false && $line !== "") $lines[] = $line;
      $f->next();
    }
    return implode($lines);
  } catch (Exception $e) {
    return "";
  }
}

/**
 * Get the last parity check from the parity history log.
 */
function last_parity_log(): array {
  $log = '/boot/config/parity-checks.log';
  if (file_exists($log)) {
    $last_line = tail($log);
    [$date_str, $duration, $speed, $status, $error, $action, $size] = my_explode('|', $last_line, 7);
  } else {
    return array_fill(0, 7, 0);
  }

  $date = 0;
  if ($date_str) {
    [$y, $m, $d, $t] = my_preg_split('/ +/', $date_str, 4);
    $date = strtotime("$d-$m-$y $t") ?: 0;
  }
  return [(int)$date, $duration, $speed, $status, $error, $action, $size];
}

/**
 * Get the last parity check from temporary system files.
 */
function last_parity_check(): array {
  global $var;
  $stamps_file = '/var/tmp/stamps.ini';
  $resync_file = '/var/tmp/resync.ini';

  $synced = file_exists($stamps_file) ? explode(',', (string)file_get_contents($stamps_file)) : [];
  $sbSynced = array_shift($synced) ?: _var($var, 'sbSynced', 0);

  $idle_duration = 0;
  while (count($synced) > 1) {
    $idle_duration += (array_pop($synced) - array_pop($synced));
  }

  $action = _var($var, 'mdResyncAction');
  $size   = _var($var, 'mdResyncSize', 0);
  if (file_exists($resync_file)) {
    [$action, $size] = my_explode(',', (string)file_get_contents($resync_file));
  }

  $duration = _var($var, 'sbSynced2', 0) - $sbSynced - $idle_duration;
  $status   = _var($var, 'sbSyncExit');
  $speed    = ($status == 0 && $duration > 0) ? round($size * 1024 / $duration) : 0;
  $error    = _var($var, 'sbSyncErrs', 0);

  return [$duration, $speed, $status, $error, $action, $size];
}

function urlencode_path(string $path): string {
  return str_replace("%2F", "/", urlencode($path));
}

/**
 * Check for deprecated filesystems on a disk.
 */
function check_deprecated_filesystem($disk): array {
  $fsType = _var($disk, 'fsType', '');
  $name = _var($disk, 'name', '');
  $warnings = [];
  
  if (stripos($fsType, 'reiserfs') !== false) {
    $warnings[] = [
      'type' => 'reiserfs',
      'severity' => 'critical',
      'message' => _('ReiserFS is deprecated and is no longer supported in Unraid. You will need to downgrade to Unraid 7.2 to take action.')
    ];
  }
  
  if (stripos($fsType, 'xfs') !== false) {
    $mountPoint = "/mnt/$name";
    if (is_dir($mountPoint) && exec(sprintf("mountpoint -q %s 2>/dev/null", escapeshellarg($mountPoint)), $output, $ret) === "" && $ret == 0) {
      $xfsInfo = shell_exec(sprintf("xfs_info %s 2>/dev/null", escapeshellarg($mountPoint)));
      if ($xfsInfo && strpos($xfsInfo, 'crc=0') !== false) {
        $warnings[] = [
          'type' => 'xfs_v4',
          'severity' => 'critical',
          'message' => _('XFS v4 is deprecated and will not be supported in future Unraid releases. Please migrate to XFS v5 immediately.')
        ];
      }
    }
  }
  return $warnings;
}

/**
 * Get filesystem warning icon HTML.
 */
function get_filesystem_warning_icon(array $warnings): string {
  if (empty($warnings)) return '';
  $hasCritical = false;
  $msgs = [];
  foreach ($warnings as $w) {
    if ($w['severity'] === 'critical') $hasCritical = true;
    $msgs[] = $w['message'];
  }
  $icon = $hasCritical ? 'exclamation-triangle' : 'exclamation-circle';
  $color = $hasCritical ? 'red-text' : 'orange-text';
  return " <i class='fa fa-$icon $color' title='" . htmlspecialchars(implode('. ', $msgs), ENT_QUOTES) . "'></i>";
}

/**
 * Check if a process is running.
 */
function pgrep(string $process_name, bool $escape_arg = true) {
  $cmd = 'pgrep --ns $$ ' . ($escape_arg ? escapeshellarg($process_name) : $process_name);
  $pid = exec($cmd, $output, $retval);
  return ($retval === 0) ? $pid : false;
}

/**
 * Check if path is a block device.
 */
function is_block(string $path): bool {
  $real = realpath($path);
  return $real ? (@filetype($real) === 'block') : false;
}

/**
 * Append file modification time to URL for cache busting.
 */
function autov(string $file, bool $ret = false) {
  global $docroot;
  $path = $docroot . $file;
  clearstatcache(true, $path);
  $time = is_file($path) ? filemtime($path) : 'autov_fileDoesntExist';
  $newFile = "$file?v=$time";
  if ($ret) return $newFile;
  echo $newFile;
}

/**
 * Resolve user share path to actual disk path.
 */
function transpose_user_path(string $path): string {
  if (strpos($path, '/mnt/user/') === 0 && file_exists($path)) {
    $realdisk = trim((string)shell_exec(sprintf("getfattr --absolute-names --only-values -n system.LOCATION %s 2>/dev/null", escapeshellarg($path))));
    if ($realdisk !== "") {
      $path = str_replace('/mnt/user/', "/mnt/$realdisk/", $path);
    }
  }
  return $path;
}

/**
 * Get list of CPUs.
 */
function cpu_list(): array {
  exec('cat /sys/devices/system/cpu/*/topology/thread_siblings_list 2>/dev/null | sort -nu', $cpus);
  return $cpus;
}

/**
 * Split string into padded array.
 * Note: Also defined in Wrappers.php
 */
if (!function_exists('my_explode')) {
function my_explode($split, $text, $count = 2): array {
  return array_pad(explode($split, $text ?? "", $count), $count, '');
}
}

function my_preg_split($split, $text, $count = 2): array {
  return array_pad(preg_split($split, (string)$text, $count), $count, '');
}

/**
 * Delete one or more files.
 */
function delete_file(...$files): void {
  foreach ($files as $f) {
    if (is_file($f)) @unlink($f);
  }
}

/**
 * Create a directory, with support for ZFS datasets and BTRFS subvolumes.
 */
function my_mkdir(string $dirname, int $permissions = 0777, bool $recursive = false, string $own = "nobody", string $grp = "users") {
  if (is_dir($dirname)) return false;

  $parent = $dirname;
  while (!is_dir($parent)) {
    if (!$recursive) return false;
    $parent = dirname($parent);
  }

  if (strpos($dirname, '/mnt/user/') === 0) {
    $realdisk = trim((string)shell_exec(sprintf("getfattr --absolute-names --only-values -n system.LOCATION %s 2>/dev/null", escapeshellarg($parent))));
    if ($realdisk !== "") {
      $dirname = str_replace('/mnt/user/', "/mnt/$realdisk/", $dirname);
      $parent = str_replace('/mnt/user/', "/mnt/$realdisk/", $parent);
    }
  }

  $fstype = trim((string)shell_exec(sprintf("stat -f -c '%%T' %s 2>/dev/null", escapeshellarg($parent))));
  $rtncode = 0;

  switch ($fstype) {
    case "zfs":
      if (is_dir("$parent/.zfs")) {
        $zfsdataset = trim((string)shell_exec(sprintf("zfs list -H -o name %s 2>/dev/null", escapeshellarg($parent))));
        $zfsdataset .= str_replace($parent, "", $dirname);
        $cmd = sprintf("zfs create %s %s 2>&1", $recursive ? "-p" : "", escapeshellarg($zfsdataset));
        exec($cmd, $output, $rtncode);
        if ($rtncode === 0) {
          @chmod($dirname, $permissions);
        }
      } else {
        $rtncode = 1;
      }
      if ($rtncode !== 0) {
        if (@mkdir($dirname, $permissions, $recursive)) $rtncode = 0;
      }
      break;
    case "btrfs":
      $cmd = sprintf("btrfs subvolume create %s %s 2>&1", $recursive ? "--parents" : "", escapeshellarg($dirname));
      exec($cmd, $output, $rtncode);
      if ($rtncode !== 0) {
        if (@mkdir($dirname, $permissions, $recursive)) $rtncode = 0;
      } else {
        @chmod($dirname, $permissions);
      }
      break;
    default:
      if (@mkdir($dirname, $permissions, $recursive)) $rtncode = 0;
      else $rtncode = 1;
      break;
  }

  if (is_dir($dirname)) {
    @chown($dirname, $own);
    @chgrp($dirname, $grp);
  }
  return $rtncode;
}

/**
 * Remove a directory, with support for ZFS datasets and BTRFS subvolumes.
 */
function my_rmdir(string $dirname): array {
  if (!is_dir($dirname)) return ['rtncode' => false, 'type' => "NoDir"];

  if (strpos($dirname, '/mnt/user/') === 0) {
    $realdisk = trim((string)shell_exec(sprintf("getfattr --absolute-names --only-values -n system.LOCATION %s 2>/dev/null", escapeshellarg($dirname))));
    if ($realdisk !== "") {
      $dirname = str_replace('/mnt/user/', "/mnt/$realdisk/", $dirname);
    }
  }

  $fstype = trim((string)shell_exec(sprintf("stat -f -c '%%T' %s 2>/dev/null", escapeshellarg($dirname))));

  switch ($fstype) {
    case "zfs":
      $zfsdataset = trim((string)shell_exec(sprintf("zfs list -H -o name %s 2>/dev/null", escapeshellarg($dirname))));
      $cmd = sprintf("zfs destroy %s 2>&1", escapeshellarg($zfsdataset));
      $error = exec($cmd, $output, $rtncode);
      return [
        'rtncode' => $rtncode,
        'output' => $output,
        'dataset' => $zfsdataset,
        'type' => $fstype,
        'cmd' => $cmd,
        'error' => $error,
      ];
    default:
      $rtncode = @rmdir($dirname);
      return ['rtncode' => $rtncode, 'type' => $fstype];
  }
}

/**
 * Get real storage volume name for a path.
 */
function get_realvolume(string $path): string {
  if (strpos($path, "/mnt/user/") === 0) {
    return trim((string)shell_exec(sprintf("getfattr --absolute-names --only-values -n system.LOCATION %s 2>/dev/null", escapeshellarg($path))));
  }
  $parts = explode("/", str_replace("/mnt/", "", $path));
  return $parts[0] ?? "";
}

/**
 * Write to debug log if enabled.
 */
function write_logging(string $value): void {
  if (is_file("/tmp/my_mkdir_debug")) {
    @file_put_contents('/tmp/my_mkdir_output', $value, FILE_APPEND);
  }
}

/**
 * Check if a device exists and is assigned.
 */
function device_exists(string $name): bool {
  global $disks, $devs;
  $assigned = isset($disks[$name]) && strpos(_var($disks[$name], 'status'), '_NP') === false;
  return $assigned || isset($devs[$name]);
}

/**
 * Parse CPU ranges from sysfs files (e.g. "0-3,5").
 */
function parse_cpu_ranges(string $file): ?array {
  if (!is_file($file)) return null;
  $ranges = trim((string)file_get_contents($file));
  if ($ranges === '') return null;
  $cores = [];
  foreach (explode(',', $ranges) as $range) {
    if (strpos($range, '-') !== false) {
      [$start, $end] = explode('-', $range);
      $cores = array_merge($cores, range((int)$start, (int)$end));
    } else {
      $cores[] = (int)$range;
    }
  }
  return $cores;
}

/**
 * Get Intel P-Core and E-Core types.
 */
function get_intel_core_types(): array {
  $core_types = [];
  $p_cores = parse_cpu_ranges("/sys/devices/cpu_core/cpus");
  $e_cores = parse_cpu_ranges("/sys/devices/cpu_atom/cpus");
  if ($p_cores) foreach ($p_cores as $c) $core_types[$c] = _("P-Core");
  if ($e_cores) foreach ($e_cores as $c) $core_types[$c] = _("E-Core");
  return $core_types;
}

/**
 * Parse dmidecode output.
 */
function dmidecode(string $key, $n, bool $all = true): array {
  $output = (string)shell_exec(sprintf("dmidecode -qt%s 2>/dev/null", escapeshellarg((string)$n)));
  $entries = array_filter(explode($key, $output));
  $properties = [];
  foreach ($entries as $entry) {
    $prop = [];
    foreach (explode("\n", $entry) as $line) {
      if (strpos($line, ': ') !== false) {
        [$k, $v] = my_explode(': ', trim($line));
        $prop[$k] = $v;
      }
    }
    if (!empty($prop)) $properties[] = $prop;
  }
  return $all ? $properties : ($properties[0] ?? []);
}

function is_intel_cpu(): bool {
  $model = exec("grep -Pom1 '^model name\s+:\s*\K.+' /proc/cpuinfo 2>/dev/null");
  return stripos($model ?? '', "intel") !== false;
}

/**
 * Load JSON data from file.
 */
function loadSavedData(string $filename): array {
  if (!is_file($filename)) return [];
  $data = @file_get_contents($filename);
  return $data ? (json_decode($data, true) ?: []) : [];
}

/**
 * Get current PCI devices using lspci.
 */
function loadCurrentPCIData(): array {
  if (is_file("/boot/config/current.json")) return loadSavedData("/boot/config/current.json");

  $output = (string)shell_exec('lspci -Dmn');
  $devices = [];
  foreach (explode("\n", trim($output)) as $line) {
    $parts = explode(" ", $line);
    if (count($parts) < 6) continue;
    $addr = $parts[0];
    $desc = trim((string)shell_exec(sprintf("lspci -s %s 2>/dev/null | sed -r 's/^\S+\s+//'", escapeshellarg($addr))));
    $devices[$addr] = [
      'class'       => trim($parts[1], '"'),
      'vendor_id'   => trim($parts[2], '"'),
      'device_id'   => trim($parts[3], '"'),
      'description' => $desc,
    ];
  }
  return $devices;
}

/**
 * Compare saved PCI data with current system state.
 */
function comparePCIData(): array {
  $saved = loadSavedData("/boot/config/savedpcidata.json");
  if (empty($saved)) return [];
  $current = loadCurrentPCIData();
  $changes = [];

  foreach ($saved as $addr => $s_dev) {
    if (!isset($current[$addr])) {
      $changes[$addr] = ['status' => 'removed', 'device' => $s_dev];
    } else {
      $c_dev = $current[$addr];
      $diffs = [];
      foreach (['vendor_id', 'device_id', 'class'] as $f) {
        if (($s_dev[$f] ?? '') !== ($c_dev[$f] ?? '')) {
          $diffs[$f] = ['old' => $s_dev[$f] ?? '', 'new' => $c_dev[$f] ?? ''];
        }
      }
      if (!empty($diffs)) {
        $changes[$addr] = ['status' => 'changed', 'device' => $c_dev, 'differences' => $diffs];
      }
    }
  }
  foreach ($current as $addr => $c_dev) {
    if (!isset($saved[$addr])) {
      $changes[$addr] = ['status' => 'added', 'device' => $c_dev];
    }
  }
  return $changes;
}

function clone_list(array $disk): bool {
  global $pools;
  $assigned = strpos(_var($disk, 'status'), '_NP') === false;
  return $assigned && (_var($disk, 'type') === 'Data' || in_array(_var($disk, 'name'), $pools ?? []));
}

/**
 * Core function to check a single disk for deprecated filesystems.
 */
function check_disk_for_deprecated_fs(array $disk): array {
  $deprecated = [];
  $fsType = strtolower(_var($disk, 'fsType', ''));
  $name = _var($disk, 'name');

  if (strpos($fsType, 'reiserfs') !== false) {
    $deprecated[] = [
      'name' => $name,
      'fsType' => 'ReiserFS',
      'severity' => 'critical',
      'message' => _('ReiserFS is deprecated and is no longer supported in Unraid. You will need to downgrade to Unraid 7.2 to take action')
    ];
  }

  if (strpos($fsType, 'xfs') !== false) {
    $mountPoint = "/mnt/$name";
    if (is_dir($mountPoint) && exec(sprintf("mountpoint -q %s 2>/dev/null", escapeshellarg($mountPoint)), $output, $ret) === "" && $ret == 0) {
      $xfsInfo = shell_exec(sprintf("xfs_info %s 2>/dev/null", escapeshellarg($mountPoint)));
      if ($xfsInfo && strpos($xfsInfo, 'crc=0') !== false) {
        $deprecated[] = [
          'name' => $name,
          'fsType' => 'XFS v4',
          'severity' => 'notice',
          'message' => _('XFS v4 is deprecated and will not be supported in future Unraid releases. You have until 2030 to migrate to XFS v5.')
        ];
      }
    }
  }
  return $deprecated;
}

/**
 * Generate inline warning HTML for a single disk.
 */
function get_inline_fs_warnings(array $disk): string {
  $warnings = check_disk_for_deprecated_fs($disk);
  $html = '';
  foreach ($warnings as $w) {
    $msg = htmlspecialchars($w['message']);
    if ($w['severity'] === 'critical') {
      $html .= "<span id='reiserfs' class='warning'><i class='fa fa-exclamation-triangle'></i>&nbsp;$msg</span>";
    } else {
      $html .= "<div id='xfsv4' style='color:#0066cc; margin: 5px 0; line-height: 1.5;'><i class='fa fa-info-circle'></i>&nbsp;$msg</div>";
    }
  }
  return $html;
}

/**
 * Check array of disks for deprecated filesystems.
 */
function check_deprecated_filesystems_array(array $disks, callable $filter_function): array {
  $deprecated = [];
  foreach ($filter_function($disks) as $disk) {
    if (substr(_var($disk, 'status', ''), 0, 7) !== 'DISK_NP') {
      $deprecated = array_merge($deprecated, check_disk_for_deprecated_fs($disk));
    }
  }
  return $deprecated;
}

/**
 * Display deprecated filesystem warnings.
 */
function display_deprecated_filesystem_warning(array $deprecated_disks, string $type = 'array'): string {
  if (empty($deprecated_disks)) return '';
  
  $critical = array_filter($deprecated_disks, fn($d) => _var($d, 'severity') === 'critical');
  $notice = array_filter($deprecated_disks, fn($d) => _var($d, 'severity') !== 'critical');
  
  $html = '';
  if (!empty($critical)) {
    $id = $type === 'array' ? 'array-critical-warning' : 'pool-critical-warning';
    $title = htmlspecialchars($type === 'array' ? _('Critical: Deprecated Filesystem') : _('Critical: Pool Deprecated Filesystem'));
    $desc = htmlspecialchars($type === 'array' ? _('The following array devices are using deprecated filesystems:') : _('The following pool devices are using deprecated filesystems:'));
    $list = "";
    foreach ($critical as $d) {
      $list .= "<li><strong>" . htmlspecialchars($d['name']) . ":</strong> " . htmlspecialchars($d['fsType']) . " - " . htmlspecialchars($d['message']) . "</li>";
    }
    $guide_text = _('View migration guide →');
    $action_req = _('Action Required:');
    $action_msg = sprintf(_('Migrate to a supported filesystem (XFS v5, BTRFS, or ZFS). %s'), "<a href='https://docs.unraid.net/go/convert-reiser-and-xfs' target='_blank' style='color: #ff8c2f;'>$guide_text</a>");
    
    $html .= <<<HTML
<div id="{$id}" style="margin: 20px 0;">
    <div style="background: #feefb3; border: 1px solid #ff8c2f; border-radius: 4px; padding: 15px; position: relative;">
        <button onclick="$('#{$id}').fadeOut();" style="position: absolute; right: 10px; top: 10px; background: transparent; border: none; color: #ff8c2f; cursor: pointer; font-size: 1.2em;"><i class="fa fa-times"></i></button>
        <div style="display: flex; align-items: start;">
            <i class="fa fa-exclamation-triangle" style="color: #ff8c2f; margin-right: 10px; font-size: 1.2em;"></i>
            <div style="flex: 1; color: #000;">
                <div style="font-weight: bold; margin-bottom: 10px; color: #ff8c2f;">{$title}</div>
                <div style="margin-bottom: 10px;">{$desc}</div>
                <ul style="margin: 10px 0 10px 20px;">{$list}</ul>
                <div style="margin-top: 10px;"><strong>{$action_req}</strong> {$action_msg}</div>
            </div>
        </div>
    </div>
</div>
HTML;
  }
  
  if (!empty($notice)) {
    $id = $type === 'array' ? 'array-notice-warning' : 'pool-notice-warning';
    $title = htmlspecialchars($type === 'array' ? _('Notice: Filesystem Update Available') : _('Notice: Pool Filesystem Update Available'));
    $desc = htmlspecialchars($type === 'array' ? _('The following array devices are using older filesystem versions:') : _('The following pool devices are using older filesystem versions:'));
    
    try {
      $deadline = new DateTime('2030-10-01');
      $now = new DateTime('now');
      if ($now < $deadline) {
        $diff = $now->diff($deadline);
        $parts = [];
        if ($diff->y > 0) $parts[] = $diff->y . ' ' . _('year' . ($diff->y != 1 ? 's' : ''));
        if ($diff->m > 0) $parts[] = $diff->m . ' ' . _('month' . ($diff->m != 1 ? 's' : ''));
        $timeline = sprintf(_('before the end of September 2030 (%s)'), implode(' ' . _('and') . ' ', $parts ?: [_('less than 1 month')]));
      } else {
        $timeline = _('as soon as possible');
      }
    } catch (Exception $e) {
      $timeline = _('as soon as possible');
    }
    
    $list = "";
    foreach ($notice as $d) {
      $list .= "<li><strong>" . htmlspecialchars($d['name']) . ":</strong> " . htmlspecialchars($d['fsType']) . " - " . htmlspecialchars($d['message']) . "</li>";
    }
    $guide_text = _('View migration guide →');
    $rec_text = _('Recommendation:');
    $rec_msg = sprintf(_('Plan to migrate to XFS v5, BTRFS, or ZFS %s. %s'), $timeline, "<a href='https://docs.unraid.net/go/convert-reiser-and-xfs' target='_blank' style='color: #0066cc;'>$guide_text</a>");

    $html .= <<<HTML
<script>
if (!sessionStorage.getItem('xfs-{$id}-dismissed')) {
  document.write(`
<div id="{$id}" style="margin: 20px 0;">
    <div style="background: #e7f3ff; border: 1px solid #0066cc; border-radius: 4px; padding: 15px; position: relative;">
        <button onclick="sessionStorage.setItem('xfs-{$id}-dismissed', 'true'); $('#{$id}').fadeOut();" style="position: absolute; right: 10px; top: 10px; background: transparent; border: none; color: #0066cc; cursor: pointer; font-size: 1.2em;" title="Dismiss until reboot"><i class="fa fa-times"></i></button>
        <div style="display: flex; align-items: start;">
            <i class="fa fa-info-circle" style="color: #0066cc; margin-right: 10px; font-size: 1.2em;"></i>
            <div style="flex: 1; color: #000;">
                <div style="font-weight: bold; margin-bottom: 10px; color: #0066cc;">{$title}</div>
                <div style="margin-bottom: 10px;">{$desc}</div>
                <ul style="margin: 10px 0 10px 20px;">{$list}</ul>
                <div style="margin-top: 10px;"><strong>{$rec_text}</strong> {$rec_msg}</div>
            </div>
        </div>
    </div>
</div>`);
}
</script>
HTML;
  }
  return $html;
}

/**
 * Get CPU packages and their siblings.
 */
function get_cpu_packages(string $separator = ','): array {
  $packages = [];
  foreach (glob("/sys/devices/system/cpu/cpu[0-9]*/topology/thread_siblings_list") as $path) {
    $pkg_id = (int)file_get_contents(dirname($path) . "/physical_package_id");
    $siblings = str_replace(",", $separator, trim((string)file_get_contents($path)));
    if (!in_array($siblings, $packages[$pkg_id] ?? [])) {
      $packages[$pkg_id][] = $siblings;
    }
  }
  foreach ($packages as &$list) {
    $keys = array_map(fn($s) => (int)explode($separator, $s)[0], $list);
    array_multisort($keys, SORT_ASC, SORT_NUMERIC, $list);
  }
  return $packages;
}

/**
 * Get IP addresses associated with a PCI device's network interfaces.
 */
function getIpAddressesByPci(string $pciAddress): array {
  $base = "/sys/bus/pci/devices/" . basename($pciAddress) . "/net";
  if (!is_dir($base)) return [];

  $interfaces = array_diff(scandir($base), ['.', '..']);
  $result = [];
  foreach ($interfaces as $iface) {
    $chain = [];
    $curr = $iface;
    while (true) {
      $chain[] = $curr;
      $masterLink = "/sys/class/net/$curr/master";
      if (!is_link($masterLink)) break;
      $curr = basename(readlink($masterLink));
    }
    foreach ($chain as $dev) {
      $output = (string)shell_exec(sprintf('ip -o addr show dev %s 2>/dev/null', escapeshellarg($dev)));
      if ($output === "") continue;
      foreach (explode("\n", trim($output)) as $line) {
        if (preg_match('/inet\s+(\d+\.\d+\.\d+\.\d+\/\d+)/', $line, $m)) $result[$dev][] = $m[1];
        if (preg_match('/inet6\s+([0-9a-fA-F:]+\/\d+)/', $line, $m)) $result[$dev][] = $m[1];
      }
    }
  }
  foreach ($result as $iface => $ips) $result[$iface] = array_values(array_unique($ips));
  return $result;
}

function getSystemNumaNodeCount(): int {
  $nodes = glob("/sys/devices/system/node/node*") ?: [];
  return count($nodes) ?: 1;
}

function normalizeNumaNode(int $node, int $numNodes): int {
  return ($numNodes === 1 && $node === -1) ? 0 : $node;
}

function getCpuNumaInfo(int $numNodes): array {
  $cpus = [];
  foreach (glob("/sys/devices/system/cpu/cpu[0-9]*") as $path) {
    $cpu = basename($path);
    $nodes = glob("$path/node*");
    $node = !empty($nodes) ? (int)str_replace("node", "", basename($nodes[0])) : -1;
    $cpus[$cpu] = [
      "cpu_id" => (int)str_replace("cpu", "", $cpu),
      "numa_node" => normalizeNumaNode($node, $numNodes)
    ];
  }
  return $cpus;
}

function getPciNumaInfo(int $numNodes): array {
  $pci = [];
  foreach (glob("/sys/bus/pci/devices/*") as $path) {
    $dev = basename($path);
    $node = is_file("$path/numa_node") ? (int)trim((string)file_get_contents("$path/numa_node")) : -1;
    $desc = trim((string)shell_exec(sprintf("lspci -mm -s %s 2>/dev/null", escapeshellarg($dev))));
    $pci[$dev] = [
      "pci_address" => $dev,
      "numa_node" => normalizeNumaNode($node, $numNodes),
      "description" => $desc
    ];
  }
  return $pci;
}

function getNumaInfo(): array {
  $numNodes = getSystemNumaNodeCount();
  $result = [
    "system" => ["numa_nodes" => $numNodes],
    "cpus" => getCpuNumaInfo($numNodes),
    "pci_devices" => getPciNumaInfo($numNodes),
  ];
  if (is_file("/tmp/numain")) {
    $override = json_decode((string)file_get_contents("/tmp/numain"), true);
    if (is_array($override)) $result = $override;
  }
  return $result;
}

/**
 * Get PCIe link data.
 */
function getPciLinkInfo(string $pciAddress): array {
  $base = "/sys/bus/pci/devices/" . basename($pciAddress);
  $out = [
    "current_speed"    => null, "max_speed" => null,
    "current_width"    => null, "max_width" => null,
    "speed_downgraded" => false, "width_downgraded" => false,
    "rate"             => "GT/s", "generation" => null,
  ];
  if (!is_dir($base)) return $out;

  $fields = ["current_link_speed" => "current_speed", "max_link_speed" => "max_speed"];
  foreach ($fields as $file => $key) {
    if (is_file("$base/$file")) {
      $val = trim((string)file_get_contents("$base/$file"));
      if (preg_match('/([0-9.]+)/', $val, $m)) $out[$key] = floatval($m[1]);
    }
  }

  $max_w = is_file("$base/max_link_width") ? (int)trim((string)file_get_contents("$base/max_link_width")) : null;
  $cur_w = is_file("$base/current_link_width") ? (int)trim((string)file_get_contents("$base/current_link_width")) : null;

  if ($max_w !== null && $max_w !== 255) {
    $out["max_width"] = $max_w;
    $out["current_width"] = $cur_w;
  }

  $class = is_file("$base/class") ? trim((string)file_get_contents("$base/class")) : "";
  $is_bridge = (strpos($class, "0x06") === 0);

  if (!$is_bridge) {
    if ($out["current_speed"] && $out["max_speed"]) $out["speed_downgraded"] = ($out["current_speed"] < $out["max_speed"]);
    if ($out["current_width"] !== null && $out["max_width"] !== null) $out["width_downgraded"] = ($out["current_width"] < $out["max_width"]);
  }

  $genTable = [1 => 2.5, 2 => 5.0, 3 => 8.0, 4 => 16.0, 5 => 32.0, 6 => 64.0];
  if ($out["max_speed"]) {
    foreach ($genTable as $gen => $gt) {
      if (abs($out["max_speed"] - $gt) < 0.5) {
        $out["generation"] = $gen;
        break;
      }
    }
  }
  return $out;
}

function has_zfs_errors($value): bool {
  return parse_si_number($value) > 0;
}

function parse_si_number($value): int {
  if (is_int($value)) return $value;
  $value = trim((string)$value);
  if ($value === '' || $value === '0') return 0;
  if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTPEZY])?$/i', $value, $m)) {
    $num = (float)$m[1];
    $suffix = strtoupper($m[2] ?? '');
    $mult = ['K' => 1e3, 'M' => 1e6, 'G' => 1e9, 'T' => 1e12, 'P' => 1e15, 'E' => 1e18];
    return (int)($num * ($mult[$suffix] ?? 1));
  }
  return (int)$value;
}

function normalize_pool_member_device(string $path): string {
  $path = basename(trim($path));
  if ($path === '') return '';
  if (preg_match('/^nvme\d+n\d+$/', $path)) return $path;
  if (preg_match('/^nvme\d+n\d+(?:p\d+|-part\d+)$/', $path)) return preg_replace('/-part\d+$|p\d+$/', '', $path);
  if (preg_match('/-part\d+$/', $path)) return preg_replace('/-part\d+$/', '', $path);
  if (preg_match('/^mmcblk\d+p\d+$/', $path)) return preg_replace('/p\d+$/', '', $path);
  if (preg_match('/^(sd|hd|vd|xvd|ubd)([a-z]+)(\d+)$/', $path, $m)) return $m[1] . $m[2];
  return $path;
}

/**
 * Get storage pool status in JSON format.
 */
function storagePoolsJson(): string {
  $result = ['source' => 'unraid', 'pools' => [], 'generated_at' => gmdate('c')];
  $ini_file = '/usr/local/emhttp/state/disks.ini';
  if (!is_readable($ini_file)) return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

  $ini = parse_ini_file($ini_file, true, INI_SCANNER_RAW) ?: [];
  $allPoolNames = [];
  foreach ($ini as $name => $data) {
    if (!preg_match('/^disk/i', $name) && in_array(_var($data, 'fsType'), ['btrfs', 'zfs', 'luks:btrfs', 'luks:zfs'])) {
      $allPoolNames[] = strtolower($name);
    }
  }

  foreach ($ini as $poolName => $s) {
    if (preg_match('/^disk/i', $poolName)) continue;
    if (!in_array(_var($s, 'fsType'), ['btrfs', 'zfs', 'luks:btrfs', 'luks:zfs']) || _var($s, 'fsStatus') !== 'Mounted') continue;

    $pool = [
      'name' => $poolName, 'fstype' => $s['fsType'], 'mountpoint' => $s['fsMountpoint'],
      'uuid' => $s['uuid'] ?? null, 'role' => $s['type'] ?? null, 'size' => $s['fsSize'] ?? null,
      'used' => $s['fsUsed'] ?? null, 'free' => $s['fsFree'] ?? null,
      'members' => [], 'overall_status' => 'UNKNOWN', 'source' => 'disks.ini',
    ];

    $prefix = preg_replace('/\d+$/', '', $poolName);
    $expected = array_filter($ini, fn($k) => !preg_match('/^disk/i', $k) && preg_replace('/\d+$/', '', $k) === $prefix, ARRAY_FILTER_USE_KEY);

    if (strpos($s['fsType'], 'btrfs') !== false) {
      $mount = escapeshellarg($s['fsMountpoint']);
      $uuid_esc = isset($s['uuid']) ? escapeshellarg($s['uuid']) : "";
      $cmd = $uuid_esc ? "btrfs filesystem show $uuid_esc 2>/dev/null" : "btrfs filesystem show $mount 2>/dev/null";
      exec($cmd, $show, $rc);
      if ($rc === 0) {
        $hasM = false; $hasF = false;
        foreach ($show as $line) {
          if (preg_match('/^\s+devid\s+(\d+)\s+size\s+(\S+)\s+used\s+(\S+)\s+path\s+(\S+)/', $line, $m)) {
            $path = $m[4];
            $isM = (stripos($path, '<missing') === 0);
            $isZ = ($m[2] === '0' || (float)$m[2] == 0.0);
            $key = $isM ? "missing_devid{$m[1]}" : normalize_pool_member_device($path);
            $pool['members'][$key] = [
              'devid' => $m[1], 'device' => $isM ? '<missing>' : $key, 'size' => $m[2], 'used' => $m[3],
              'status' => $isM ? 'MISSING' : ($isZ ? 'FAILED' : 'ONLINE'),
              'errors' => ['write' => 0, 'read' => 0, 'flush' => 0, 'corruption' => 0, 'generation' => 0],
            ];
            if ($isM) $hasM = true; elseif ($isZ) $hasF = true;
          }
        }
        exec("btrfs device stats $mount 2>/dev/null", $stats, $rc2);
        if ($rc2 === 0) foreach ($stats as $line) {
          if (preg_match('/^\[([^\]]+)\]\.(\S+)\s+(\d+)/', $line, $m)) {
            $dev = normalize_pool_member_device($m[1]);
            $type = str_replace('_io_errs', '', str_replace('_errs', '', $m[2]));
            $val = (int)$m[3];
            foreach ($pool['members'] as $k => &$mem) {
              if ($k === $dev || $mem['device'] === $dev) {
                $mem['errors'][$type] = $val;
                if ($val > 0 && $mem['status'] === 'ONLINE') $mem['status'] = 'ERRORS';
              }
            }
          }
        }
        foreach ($expected as $dName => $dData) {
          $found = false;
          foreach ($pool['members'] as $mKey => $mem) {
            if (strpos($mKey, preg_replace('/\d+$/', '', $dName)) === 0 || strpos($mem['device'], $dName) !== false) { $found = true; break; }
          }
          if (!$found && _var($dData, 'status') === 'DISK_NP_DSBL') {
            $pool['members'][$dName] = ['devid' => '?', 'device' => $dName, 'size' => 'N/A', 'used' => 'N/A', 'status' => 'REMOVED'];
            $hasM = true;
          }
        }
        $statuses = array_column($pool['members'], 'status');
        $pool['overall_status'] = ($hasM || $hasF || array_intersect(['MISSING', 'FAILED', 'DEGRADED', 'REMOVED'], $statuses)) ? 'DEGRADED' : 'ONLINE';
        $totalE = 0;
        foreach ($pool['members'] as $m) foreach (($m['errors'] ?? []) as $e) $totalE += $e;
        $pool['total_errors'] = $totalE;
        if ($totalE > 0 && $pool['overall_status'] === 'ONLINE') $pool['overall_status'] .= ' - ERRORS';
      }
    }

    if (strpos($s['fsType'], 'zfs') !== false) {
      $actualPool = $poolName;
      exec(sprintf("zfs list -H -o name %s 2>/dev/null", escapeshellarg($poolName)), $zList, $zRc);
      if ($zRc === 0 && !empty($zList)) {
        $fs = trim($zList[0]);
        if (($pos = strpos($fs, '/')) !== false) $actualPool = substr($fs, 0, $pos);
      }
      $apEsc = escapeshellarg($actualPool);
      exec("zpool status -j $apEsc 2>/dev/null", $zj, $rc);
      if ($rc === 0 && ($zd = json_decode(implode('', $zj), true)) && isset($zd['pools'][$actualPool])) {
        $poolData = $zd['pools'][$actualPool];
        $pool['overall_status'] = strtoupper($poolData['state'] ?? 'UNKNOWN');
        $extract = function($vdev, $pType = 'data') use (&$extract, &$pool, $allPoolNames) {
          $type = in_array($vdev['type'] ?? '', ['spare', 'cache', 'log', 'special', 'dedup']) ? $vdev['type'] : $pType;
          if (!empty($vdev['children'])) foreach ($vdev['children'] as $c) $extract($c, $type);
          if (isset($vdev['path'])) {
            $dev = normalize_pool_member_device($vdev['path']);
            if (!in_array(strtolower($dev), $allPoolNames)) {
              $e = ['read' => $vdev['read_errors'] ?? 0, 'write' => $vdev['write_errors'] ?? 0, 'checksum' => $vdev['checksum_errors'] ?? 0];
              $pool['members'][$dev] = [
                'device' => $dev, 'status' => (has_zfs_errors($e['read']) || has_zfs_errors($e['write']) || has_zfs_errors($e['checksum'])) ? 'ERRORS' : strtoupper($vdev['state'] ?? 'UNKNOWN'),
                'vdev' => $vdev['name'] ?? $vdev['type'], 'type' => $type, 'errors' => $e
              ];
            }
          }
        };
        $extract($poolData['config'] ?? $poolData['vdev_tree'] ?? []);
      }
      foreach ($expected as $dName => $dData) {
        $found = false;
        foreach ($pool['members'] as $mKey => $mem) {
          if (strpos($mKey, preg_replace('/\d+$/', '', $dName)) === 0 || strpos($mem['device'], $dName) !== false) { $found = true; break; }
        }
        if (!$found && _var($dData, 'status') === 'DISK_NP_DSBL') {
          $pool['members'][$dName] = ['device' => $dName, 'status' => 'MISSING', 'vdev' => null, 'type' => 'data'];
          if (in_array($pool['overall_status'], ['ONLINE', 'UNKNOWN'])) $pool['overall_status'] = 'ERRORS';
        }
      }
      $totalE = 0;
      foreach ($pool['members'] as $m) foreach (($m['errors'] ?? []) as $e) $totalE += parse_si_number($e);
      $pool['total_errors'] = $totalE;
      if ($totalE > 0 && $pool['overall_status'] === 'ONLINE') $pool['overall_status'] .= ' - ERRORS';
      $pool['zfs_type'] = ($actualPool !== $poolName) ? 'dataset' : 'pool';
      if ($pool['zfs_type'] === 'dataset') $pool['parent_pool'] = $actualPool;
    }
    $result['pools'][$poolName] = $pool;
  }
  return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function get_block_devices(): array {
  exec('lsblk -ndo NAME 2>/dev/null', $out);
  $devs = [];
  foreach ($out as $l) if (preg_match('/^sd[a-z]+$/', trim($l))) $devs[] = trim($l);
  sort($devs);
  return $devs;
}

function sysfs_read(string $path): ?string {
  return is_readable($path) ? (($v = trim((string)@file_get_contents($path))) !== '' ? $v : null) : null;
}

function udev_property(string $device, string $key): ?string {
  exec(sprintf('udevadm info --query=property --name=%s 2>/dev/null', escapeshellarg($device)), $out);
  foreach ($out as $line) if (strpos($line, "$key=") === 0) return substr($line, strlen($key) + 1);
  return null;
}

function get_disk_identity(string $sd): array {
  $dev = "/dev/$sd";
  return ['device' => $dev, 'wwid' => sysfs_read("/sys/block/$sd/device/wwid"), 'serial' => udev_property($dev, 'ID_SERIAL')];
}

function find_duplicate_disks_json(): string {
  $all = []; $bySerial = [];
  foreach (get_block_devices() as $sd) {
    $d = get_disk_identity($sd);
    $all[$d['device']] = $d;
    if ($d['serial']) $bySerial[$d['serial']][] = $d['device'];
  }
  $result = [];
  foreach ($all as $dev => $info) {
    if ($info['serial'] && count($bySerial[$info['serial']]) > 1) {
      $result[$dev] = ['duplicate' => 'serial', 'duplicate_value' => $info['serial'], 'other_devices' => array_values(array_diff($bySerial[$info['serial']], [$dev]))];
    }
  }
  return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
?>

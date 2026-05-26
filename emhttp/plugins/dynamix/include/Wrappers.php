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

// pool name ending in any of these => zfs subpool
$subpools = ['special','logs','dedup','cache','spares'];

// ZFS subpool name separator and replacement
$_tilde_ = '~';
$_proxy_ = '__';
$_arrow_ = '&#187;';

/**
 * Atomically write data to a file.
 *
 * @param string $filename
 * @param string $data
 * @return int|false Number of bytes written or false on failure.
 */
function file_put_contents_atomic(string $filename, string $data) {
  $dir = dirname($filename);
  if (!is_dir($dir)) return false;
  $temp = tempnam($dir, basename($filename));
  if ($temp === false) return false;

  if (@file_put_contents($temp, $data) !== strlen($data)) {
    @unlink($temp);
    return false;
  }

  if (!@rename($temp, $filename)) {
    @unlink($temp);
    return false;
  }

  return strlen($data);
}

/**
 * Custom parse_ini_string to handle '#' comments and strip tags.
 */
function my_parse_ini_string($text, bool $sections = false, int $scanner = INI_SCANNER_NORMAL) {
  if ($text === null || $text === false) return false;
  $clean = strip_tags(html_entity_decode(preg_replace('/^#.*$/m', '', (string)$text)));
  return parse_ini_string($clean, $sections, $scanner);
}

/**
 * Custom parse_ini_file to handle '#' comments and strip tags.
 */
function my_parse_ini_file(string $file, bool $sections = false, int $scanner = INI_SCANNER_NORMAL) {
  $content = @file_get_contents($file);
  return my_parse_ini_string($content, $sections, $scanner);
}

/**
 * Parse plugin configuration by merging default and custom settings.
 */
function parse_plugin_cfg(string $plugin, bool $sections = false, int $scanner = INI_SCANNER_NORMAL): array {
  global $docroot;
  $ram = "$docroot/plugins/$plugin/default.cfg";
  $rom = "/boot/config/plugins/$plugin/$plugin.cfg";
  
  $cfg_ram = is_file($ram) ? (my_parse_ini_file($ram, $sections, $scanner) ?: []) : [];
  $cfg_rom = is_file($rom) ? (my_parse_ini_file($rom, $sections, $scanner) ?: []) : [];

  return array_replace_recursive($cfg_ram, $cfg_rom);
}

/**
 * Update a cron job for a plugin.
 */
function parse_cron_cfg(string $plugin, string $job, string $text = "") {
  $cron = "/boot/config/plugins/$plugin/$job.cron";
  if ($text !== "") {
    file_put_contents($cron, $text);
  } else {
    @unlink($cron);
  }
  exec("/usr/local/sbin/update_cron");
}

/**
 * Get the full path for a notification agent.
 */
function agent_fullname(string $agent, string $state): string {
  switch ($state) {
    case 'enabled' : return "/boot/config/plugins/dynamix/notifications/agents/$agent";
    case 'disabled': return "/boot/config/plugins/dynamix/notifications/agents-disabled/$agent";
    default        : return $agent;
  }
}

/**
 * Get an attribute from a plugin file.
 */
function get_plugin_attr(string $attr, string $file): ?string {
  global $docroot;
  $cmd = sprintf("%s/plugins/dynamix.plugin.manager/scripts/plugin %s %s", $docroot, escapeshellarg($attr), escapeshellarg($file));
  exec($cmd, $result, $error);
  return ($error === 0) ? ($result[0] ?? null) : null;
}

/**
 * Check if a plugin update is available.
 */
function plugin_update_available(string $plugin, bool $os = false): ?string {
  $local  = get_plugin_attr('version', "/var/log/plugins/$plugin.plg");
  $remote = get_plugin_attr('version', "/tmp/plugins/$plugin.plg");

  if ($remote && strcmp($remote, $local ?? '') > 0) {
    if ($os) return $remote;
    $unraid = get_plugin_attr('Unraid', "/tmp/plugins/$plugin.plg");
    if (!$unraid) return $remote;
    $server = get_plugin_attr('version', "/var/log/plugins/unRAIDServer.plg");
    if (version_compare($server ?? '', $unraid, '>=')) return $remote;
  }
  return null;
}

/**
 * Safely get a variable or array key.
 */
function _var(&$name, $key = null, $default = '') {
  if (is_null($key)) return $name ?? $default;
  return (is_array($name) && isset($name[$key])) ? $name[$key] : $default;
}

function celsius($temp) {
  return round(($temp - 32) * 5 / 9);
}

function fahrenheit($temp) {
  return round(9 / 5 * $temp) + 32;
}

function displayTemp($temp) {
  global $display;
  return (is_numeric($temp) && _var($display, 'unit') == 'F') ? fahrenheit($temp) : $temp;
}

function get_value(&$name, $key, $default) {
  global $var;
  $value = $name[$key] ?? -1;
  return ($value !== -1) ? $value : ($var[$key] ?? $default);
}

function get_ctlr_options(&$type, &$disk) {
  if (!$type) return;
  $ports = [];
  foreach (['smPort1', 'smPort2', 'smPort3'] as $p) {
    if (isset($disk[$p])) $ports[] = $disk[$p];
  }
  if ($ports) {
    $type .= ',' . implode($disk['smGlue'] ?? ',', $ports);
  }
}

function port_name(string $port): string {
  return (substr($port, -2) === 'n1') ? substr($port, 0, -2) : $port;
}

function exceed($value, $limit, $top = 100): bool {
  return is_numeric($value) && $limit > 0 && $value > $limit && $value <= $top;
}

/**
 * Get IP addresses for a given interface.
 */
function ipaddr(string $ethX = 'eth0', int $prot = 4) {
  $wlan = ($ethX === 'eth0' && lan_port('wlan0') && lan_port('wlan0', true));

  $get_ipv4 = function($iface) {
    return exec(sprintf("ip -4 -br addr show %s scope global | awk '{print \$3;exit}' | sed -r 's/\/[0-9]+//'", escapeshellarg($iface)));
  };
  $get_ipv6 = function($iface) {
    return exec(sprintf("ip -6 -br addr show %s scope global -temporary -deprecated | awk '{print \$3;exit}' | sed -r 's/\/[0-9]+//'", escapeshellarg($iface)));
  };

  switch ($prot) {
    case 4:
      $ip = $get_ipv4($ethX);
      return ($ip === "" && $wlan) ? $get_ipv4('wlan0') : $ip;
    case 6:
      $ip = $get_ipv6($ethX);
      return ($ip === "" && $wlan) ? $get_ipv6('wlan0') : $ip;
    default:
      $ipv4 = $get_ipv4($ethX);
      $ipv6 = $get_ipv6($ethX);
      if ($wlan) {
        $ipv4 = ($ipv4 === "") ? $get_ipv4('wlan0') : $ipv4;
        $ipv6 = ($ipv6 === "") ? $get_ipv6('wlan0') : $ipv6;
      }
      return [$ipv4, $ipv6];
  }
}

function no_tilde(string $name): string {
  global $_tilde_, $_proxy_;
  return str_replace($_tilde_, $_proxy_, $name);
}

function prefix(string $key): string {
  return preg_replace('/\d+$/', '', $key);
}

function pool_name(string $key): string {
  return preg_replace('/(\d+$|~.*$)/', '', $key);
}

function native(string $name, int $full = 0): string {
  global $_tilde_, $_arrow_;
  switch ($full) {
    case 0: return str_replace($_tilde_, " $_arrow_ ", $name);
    case 1:
      $parts = explode($_tilde_, $name);
      return (count($parts) > 1) ? "$_arrow_ " . $parts[1] : $name;
    default: return $name;
  }
}

function isSubpool(string $name) {
  global $subpools, $_tilde_;
  $parts = explode($_tilde_, $name);
  if (count($parts) < 2) return false;
  return in_array($parts[1], $subpools) ? $parts[1] : false;
}

/**
 * Get NVMe specific information.
 */
function get_nvme_info(string $device, string $info) {
  $dev = escapeshellarg("/dev/$device");
  switch ($info) {
    case 'temp':
      exec("nvme id-ctrl $dev 2>/dev/null | grep -Pom2 '^[wc]ctemp +: \K\d+'", $temp);
      return (count($temp) >= 2) ? [$temp[0] - 273, $temp[1] - 273] : [0, 0];
    case 'cctemp':
      $val = exec("nvme id-ctrl $dev 2>/dev/null | grep -Pom1 '^cctemp +: \K\d+'");
      return is_numeric($val) ? (int)$val - 273 : 0;
    case 'wctemp':
      $val = exec("nvme id-ctrl $dev 2>/dev/null | grep -Pom1 '^wctemp +: \K\d+'");
      return is_numeric($val) ? (int)$val - 273 : 0;
    case 'state':
      $state = exec("nvme get-feature $dev -f2 2>/dev/null | grep -Pom1 'value:.+\K.$'");
      return exec("nvme id-ctrl $dev 2>/dev/null | grep -Pom1 '^ps +$state : mp:\K\S+ \S+'");
    case 'power':
      $state = exec("nvme get-feature $dev -f2 2>/dev/null | grep -Pom1 'value:.+\K.$'");
      return exec("smartctl -c $dev 2>/dev/null | grep -Pom1 '^ *$state [+-] +\K[^W]+'");
    default:
      return null;
  }
}

/**
 * Convert strftime-style format to PHP date() format.
 */
function my_date(string $fmt, int $time): string {
  $legacy = [
    '%c' => 'D j M Y h:i A', '%A' => 'l', '%Y' => 'Y', '%B' => 'F',
    '%e' => 'j', '%d' => 'd', '%m' => 'm', '%I' => 'h', '%H' => 'H',
    '%M' => 'i', '%S' => 's', '%p' => 'a', '%R' => 'H:i',
    '%F' => 'Y-m-d', '%T' => 'H:i:s'
  ];
  return date(strtr($fmt, $legacy), $time);
}

/**
 * Log a message to the system log.
 */
function my_logger(string $message, string $logger = 'webgui') {
  exec('logger -t ' . escapeshellarg($logger) . ' -- ' . escapeshellarg($message));
}

/**
 * Fetches URL and returns content using cURL.
 */
function http_get_contents(string $url, array $opts = [], ?array &$getinfo = null) {
  $ch = curl_init();
  if (isset($getinfo)) curl_setopt($ch, CURLINFO_HEADER_OUT, true);

  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_FRESH_CONNECT => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_ENCODING => "",
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_REFERER => "",
    CURLOPT_FAILONERROR => true,
    CURLOPT_USERAGENT => 'Unraid',
  ]);

  if (!empty($opts)) {
    foreach ($opts as $key => $val) {
      curl_setopt($ch, $key, $val);
    }
  }

  $out = curl_exec($ch);

  if (curl_errno($ch) === 23) { // CURLE_WRITE_ERROR
    curl_setopt($ch, CURLOPT_ENCODING, "deflate");
    $out = curl_exec($ch);
  }

  if (isset($getinfo)) {
    $getinfo = curl_getinfo($ch);
  }

  if ($errno = curl_errno($ch)) {
    $msg = "Curl error $errno: " . (curl_error($ch) ?: curl_strerror($errno)) . ". Requested url: '$url'";
    if (isset($getinfo)) $getinfo['error'] = $msg;
    my_logger($msg, "http_get_contents");
  }

  curl_close($ch);
  return $out;
}

/**
 * Detect network connectivity.
 */
function check_network_connectivity(): bool {
  $url = 'http://www.msftncsi.com/ncsi.txt';
  $out = http_get_contents($url);
  return ($out === "Microsoft NCSI");
}

/**
 * Check if a LAN port exists and optionally its link state.
 */
function lan_port(string $port, bool $state = false) {
  $path = "/sys/class/net/" . basename($port);
  $exist = is_dir($path);
  if (!$state) return $exist;
  if (!$exist) return false;
  $carrier = @file_get_contents("$path/carrier");
  return ($carrier !== false) ? (int)trim($carrier) : 0;
}

/**
 * Escape multiple arguments for shell use.
 */
function shieldarg(...$args): string {
  return implode(' ', array_map('escapeshellarg', $args));
}

/**
 * Helper to split string into padded array.
 */
if (!function_exists('my_explode')) {
function my_explode(string $split, ?string $text, int $count = 2): array {
  return array_pad(explode($split, $text ?? "", $count), $count, '');
}
}
?>

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
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/webGui/include/Preselect.php";

// add translations
$_SERVER['REQUEST_URI'] = 'main';
require_once "$docroot/webGui/include/Translations.php";

$disks = array_merge_recursive(@parse_ini_file('state/disks.ini',true) ?: [], @parse_ini_file('state/devs.ini',true) ?: []);
require_once "$docroot/webGui/include/CustomMerge.php";

/**
 * Normalize text for display in a table cell.
 */
function normalize(string $text, string $glue = '_'): string {
  $words = explode($glue, $text);
  foreach ($words as &$word) {
    if ($word !== strtoupper($word)) {
      $word = preg_replace(['/^(ct|cnt)$/', '/^blk$/'], ['count', 'block'], strtolower($word));
    }
  }
  return "<td>" . ucfirst(implode(' ', $words)) . "</td>";
}

function size($val) {
  return str_replace(',', '', (string)$val);
}

/**
 * Add relative time information to power-on hours.
 */
function duration(&$hrs): void {
  $time = ceil(time() / 3600) * 3600;
  $run = (int)size($hrs);
  try {
    $now = new DateTime("@$time");
    $poh = new DateTime("@" . ($time - $run * 3600));
    $age = date_diff($poh, $now);
    $parts = [];
    if ($age->y) $parts[] = "{$age->y}y";
    if ($age->m) $parts[] = "{$age->m}m";
    if ($age->d) $parts[] = "{$age->d}d";
    $parts[] = "{$age->h}h";
    $hrs = "$hrs (" . implode(', ', $parts) . ")";
  } catch (Exception $e) {
    // Keep original value on error
  }
}

function blocks_size(&$blks, $blk_size): void {
  $blks = "$blks (" . my_scale((float)$blks * $blk_size, $unit) . " $unit)";
}

function append(&$ref, &$info): void {
  if ($info !== null && $info !== "") {
    $ref .= ($ref !== "" ? " " : "") . $info;
  }
}

$name = $_POST['name'] ?? '';
$port = $_POST['port'] ?? '';

if ($name !== "" && isset($disks[$name])) {
  $disk = &$disks[$name];
  $type = get_value($disk, 'smType', '');
  get_ctlr_options($type, $disk);
} else {
  $disk = [];
  $type = '';
}

$port = port_name($disk['smDevice'] ?? $port);
if (empty($port)) die();

$device_path = escapeshellarg("/dev/" . basename($port));
$type_arg = ($type !== "") ? $type : ""; // already escaped in get_ctlr_options if needed, or comes from disks.ini

switch ($_POST['cmd'] ?? '') {
case "attributes":
  $select = get_value($disk, 'smSelect', 0);
  $level  = get_value($disk, 'smLevel', 1);
  $events = explode('|', (string)get_value($disk, 'smEvents', $numbers));
  $cfg = parse_plugin_cfg('dynamix', true);

  [$hotNVME, $maxNVME] = (_var($disk, 'transport') === 'nvme') ? get_nvme_info(_var($disk, 'device'), 'temp') : [-1, -1];
  $hot = _var($disk, 'hotTemp', -1) >= 0 ? $disk['hotTemp'] : ($hotNVME >= 0 ? $hotNVME : (_var($disk, 'rotational', 1) == 0 && ($cfg['display']['hotssd'] ?? -1) >= 0 ? $cfg['display']['hotssd'] : ($cfg['display']['hot'] ?? 45)));
  $max = _var($disk, 'maxTemp', -1) >= 0 ? $disk['maxTemp'] : ($maxNVME >= 0 ? $maxNVME : (_var($disk, 'rotational', 1) == 0 && ($cfg['display']['maxssd'] ?? -1) >= 0 ? $cfg['display']['maxssd'] : ($cfg['display']['max'] ?? 55)));
  $top = (int)($_POST['top'] ?? 120);

  $ssd_remaining = null;
  $empty = true;
  exec("smartctl -n standby -A $type $device_path", $output);
  $output = array_filter($output);

  $start = 0;
  foreach ($output as $row) {
    if (stripos($row, 'smart attributes data structure') !== false) break;
    $start++;
  }

  if ($start < count($output) - 3) {
    $rows = array_slice($output, $start + 3);
    foreach ($rows as $row) {
      $info = explode(' ', trim(preg_replace('/\s+/', ' ', $row)), 10);
      if (count($info) < 10) continue;

      $highlight = (strpos($info[8], 'FAILING_NOW') !== false) || ($select ? ($info[5] > 0 && $info[3] <= $info[5] * $level) : ($info[9] > 0));
      $color = "";
      if (in_array($info[0], $events) && $highlight) {
        $color = " class='warn'";
      } elseif (in_array($info[0], [190, 194])) {
        if (exceed($info[9], $max, $top)) $color = " class='alert'";
        elseif (exceed($info[9], $hot, $top)) $color = " class='warn'";
      }

      if ($info[8] === '-') $info[8] = 'Never';
      if ($info[0] == 9 && is_numeric(size($info[9]))) duration($info[9]);
      if (str_starts_with($info[1], 'Total_LBAs_')) blocks_size($info[9], 512);
      if (str_ends_with($info[1], '_32MiB')) blocks_size($info[9], 32 * 1024 * 1024);

      echo "<tr$color>" . implode('', array_map('normalize', $info)) . "</tr>";
      $empty = false;
    }
  } else {
    foreach ($output as $row) {
      if (strpos($row, ':') === false) continue;
      [$attr_name, $value] = array_map('trim', explode(':', $row, 2));
      $display_name = ucfirst(strtolower($attr_name));
      $color = '';
      switch ($display_name) {
      case 'Temperature':
        $temp = strtok($value, ' ');
        if (exceed($temp, $max)) $color = " class='alert'";
        elseif (exceed($temp, $hot)) $color = " class='warn'";
        break;
      case 'Power on hours':
        if (is_numeric(size($value))) duration($value);
        break;
      case 'Percentage used':
        $ssd_remaining = 100 - (int)str_replace('%', '', $value);
        break;
      }
      if (str_ends_with($display_name, ', hours') && str_starts_with($value, 'minutes ')) {
        $display_name = substr($display_name, 0, -7);
        $value = substr($value, 8);
        if (is_numeric(size($value))) duration($value);
      }
      echo "<tr$color><td>-</td><td>" . htmlspecialchars($display_name) . "</td><td colspan='8'>" . htmlspecialchars($value) . "</td></tr>";
      $empty = false;
    }
  }

  if ($ssd_remaining === null) {
    exec("smartctl -n standby -l ssd $type $device_path", $ssd_out);
    foreach (array_filter($ssd_out) as $row) {
      if (str_ends_with($row, 'Percentage Used Endurance Indicator')) {
        $info = explode(' ', trim(preg_replace('/\s+/', ' ', $row)), 6);
        $ssd_remaining = 100 - (int)($info[3] ?? 0);
      } elseif (str_starts_with($row, 'Percentage used endurance indicator:')) {
        [$null, $val] = array_map('trim', explode(':', $row, 2));
        $ssd_remaining = 100 - (int)str_replace('%', '', $val);
      }
    }    
  }
  if ($ssd_remaining !== null) {
    printf("<tr><td>-</td><td>%s</td><td colspan='8'>%d %%</td></tr>", _('SSD endurance remaining'), $ssd_remaining);
  }  
  if ($empty) {
    printf("<tr><td colspan='10' style='text-align:center;padding-top:12px'>%s</td></tr>", _('Attributes not available'));
  }
  break;

case "capabilities":
  printf('<div class="TableContainer"><table id="disk_capabilities_table" class="unraid"><thead><td style="width:33%%">%s</td><td>%s</td><td>%s</td></thead><tbody>', _('Feature'), _('Value'), _('Information'));
  exec("smartctl -n standby -c $type $device_path | awk 'NR>5'", $output);

  $row = ['', '', ''];
  $empty = true;
  $is_nvme = str_starts_with($port, 'nvme');
  $section = "info";

  foreach ($output as $line) {
    if (!$line) { echo "<tr></tr>"; continue; }
    $line_clean = preg_replace('/^_/', '__', preg_replace(['/__+/', '/_ +_/'], '_', str_replace([chr(9), ')', '('], '_', $line)));
    $info = array_map('trim', explode('_', preg_replace('/_( +)_ /', '__', $line_clean), 3));

    if ($is_nvme && $info[0] === "Supported Power States") {
      $section = "psheading";
      printf("</tbody></table><div class='title'><span>%s</span></div>", htmlspecialchars($line));
      $row = ['', '', '']; continue;
    }
    if ($is_nvme && $info[0] === "Supported LBA Sizes") {
      printf("</tbody></table></div><div class='title'>%s %s %s</span></div>", htmlspecialchars($info[0]), htmlspecialchars($info[1] ?? ''), htmlspecialchars($info[2] ?? ''));
      $row = ['', '', ''];
      $section = "lbaheading"; continue;
    }

    append($row[0], $info[0]);
    append($row[1], $info[1]);
    append($row[2], $info[2]);

    if (str_ends_with($row[2], '.') || ($is_nvme && $section === "info")) {
      printf("<tr><td>%s</td><td>%s</td><td>%s</td></tr>", htmlspecialchars($row[0]), htmlspecialchars($row[1]), htmlspecialchars($row[2]));
      $row = ['', '', ''];
      $empty = false;
    }

    if ($is_nvme && $section === "psheading") {
      echo '<table id="disk_capabilities_table2" class="unraid"><thead><tr>';
      $section = "psdetail";
      if (preg_match('/^(?P<d1>.\S+)\s+(?P<d2>\S+)\s+(?P<d3>\S+)\s+(?P<d4>\S+)\s+(?P<d5>\S+)\s+(?P<d6>\S+)\s+(?P<d7>\S+)\s+(?P<d8>\S+)\s+(?P<d9>\S+)\s+(?P<d10>\S+)\s+(?P<d11>\S+)$/', $line, $m)) {
        for ($i = 1; $i <= 11; $i++) echo "<td>" . htmlspecialchars($m["d$i"]) . "</td>";
      }
      echo '</tr></thead><tbody>';
      $row = ['', '', ''];
    } elseif ($is_nvme && $section === "psdetail") {
      echo '<tr>';
      if (preg_match('/^(?P<d1>.\S+)\s+(?P<d2>\S\s+)\s+(?P<d3>\S+)\s+(?P<d4>\S\s+)\s+(?P<d5>\S+)\s+(?P<d6>\S+)\s+(?P<d7>\S+)\s+(?P<d8>\S+)\s+(?P<d9>\S+)\s+(?P<d10>\S+)\s+(?P<d11>\S+)$/', $line, $m)) {
        for ($i = 1; $i <= 11; $i++) echo "<td>" . htmlspecialchars($m["d$i"]) . "</td>";
      }
      echo '</tr>';
      $row = ['', '', ''];
    } elseif ($is_nvme && $section === "lbaheading") {
      echo '<table id="disk_capabilities_table3" class="unraid"><thead><tr>';
      $section = "lbadetail";
      if (preg_match('/^(?P<d1>.\S+)\s+(?P<d2>\S+)\s+(?P<d3>\S+)\s+(?P<d4>\S+)\s+(?P<d5>\S+)$/', $line, $m)) {
        for ($i = 1; $i <= 5; $i++) echo "<td>" . htmlspecialchars($m["d$i"]) . "</td>";
      }
      echo '</tr></thead><tbody>';
      $row = ['', '', ''];
    } elseif ($is_nvme && $section === "lbadetail") {
      echo '<tr>';
      if (preg_match('/^(?P<d1>.\S+)\s+(?P<d2>\S\s+)\s+(?P<d3>\S+)\s+(?P<d4>\S\s+)\s+(?P<d5>\S+)$/', $line, $m)) {
        for ($i = 1; $i <= 5; $i++) echo "<td>" . htmlspecialchars($m["d$i"]) . "</td>";
      }
      echo '</tr>';
      $row = ['', '', ''];
    }
  }
  if ($empty) printf("<tr><td colspan='3' style='text-align:center;padding-top:12px'>%s</td></tr>", _('Capabilities not available'));
  echo "</tbody></table></div>";
  break;

case "identify":
  $passed = ['PASSED', 'OK'];
  $failed = ['FAILED', 'NOK'];
  $standby = (_var($disk, 'transport') === "scsi") ? " -n standby " : "";

  exec("smartctl -i $type $standby $device_path | awk 'NR>4'", $output);
  exec("smartctl -n standby -H $type $device_path | grep -Pom1 '^SMART.*: [A-Z]+' | sed 's:self-assessment test result::'", $output);

  $empty = true;
  foreach ($output as $line) {
    if (!$line || strpos($line, 'VALID ARGUMENTS') !== false) continue;
    [$title, $val] = array_map('trim', my_explode(':', $line));
    if (in_array($val, $passed)) $val = "<span class='green-text'>" . _('Passed') . "</span>";
    elseif (in_array($val, $failed)) $val = "<span class='red-text'>" . _('Failed') . "</span>";
    echo "<tr>" . normalize(preg_replace('/ is:$/', ':', "$title:"), ' ') . "<td>$val</td></tr>";
    $empty = false;
  }

  if ($empty) {
    $extra_msg = _var($disk, 'spundown') ? " (" . _("device spundown, spinup to get information") . ")" : "";
    printf("<tr><td colspan='2' style='text-align:center;padding-top:12px'>%s%s</td></tr>", _('Identification not available'), $extra_msg);
  } else {
    $log_file = '/boot/config/disk.log';
    $disk_id = $disk['id'] ?? '';
    $extra_info = (is_file($log_file) ? parse_ini_file($log_file, true) : [])[$disk_id] ?? [];
    $periods = ['6','12','18','24','36','48','60'];

    printf("<tr><td>%s:</td><td><input type='date' class='narrow' value='%s' onchange='disklog(\"%s\",\"date\",this.value)'></td></tr>", _('Manufacturing date'), htmlspecialchars(_var($extra_info, 'date')), htmlspecialchars($disk_id));
    printf("<tr><td>%s:</td><td><input type='date' class='narrow' value='%s' onchange='disklog(\"%s\",\"purchase\",this.value)'></td></tr>", _('Date of purchase'), htmlspecialchars($extra_info['purchase'] ?? ''), htmlspecialchars($disk_id));
    printf("<tr><td>%s:</td><td><select class='noframe' onchange='disklog(\"%s\",\"warranty\",this.value)'><option value=''>%s</option>", _('Warranty period'), htmlspecialchars($disk_id), _('unknown'));
    foreach ($periods as $p) {
      printf("<option value='%s'%s>%s %s</option>", $p, (_var($extra_info, 'warranty') == $p ? " selected" : ""), $p, _('months'));
    }
    echo "</select></td></tr>";
  }
  break;

case "save":
  $target_file = basename($_POST['file'] ?? '');
  if ($target_file !== "") {
    $target_path = escapeshellarg("$docroot/$target_file");
    exec("smartctl -x $type $device_path > $target_path");
  }
  break;

case "delete":
  $target_file = basename($_POST['file'] ?? '');
  if ($target_file !== "") {
    $target_path = "/var/tmp/$target_file";
    if (is_file($target_path)) @unlink($target_path);
  }
  break;

case "short":
  exec("smartctl -t short $type $device_path");
  break;

case "long":
  exec("smartctl -t long $type $device_path");
  break;

case "stop":
  exec("smartctl -X $type $device_path");
  break;

case "update":
  $transport = _var($disk, 'transport');
  $progress_cmd = ($transport === 'scsi' || $transport === 'nvme') ? "smartctl -n standby -l selftest" : "smartctl -n standby -c";
  $progress = exec("$progress_cmd $type $device_path | grep -Pom1 '\d+%%'");

  if ($progress) {
    $percent = (int)substr($progress, 0, -1);
    if ($transport === 'nvme') $completed = $percent;
    else $completed = 100 - $percent;
    printf("<span class='big'><i class='fa fa-spinner fa-pulse'></i> %s, %d%% %s</span>", _('self-test in progress'), $completed, _('complete'));
    break;
  }

  if ($transport === 'scsi') $res_cmd = "smartctl -n standby -l selftest $type $device_path | grep -m1 '^# 1' | cut -c24-50";
  elseif ($transport === 'nvme') $res_cmd = "smartctl -n standby -l selftest $type $device_path | grep -m1 '^ 0' | cut -c24-50";
  else $res_cmd = "smartctl -n standby -l selftest $type $device_path | grep -m1 '^# 1' | cut -c26-55";

  $result = trim((string)exec($res_cmd));
  if ($result === "") {
    $msg = _var($disk, 'spundown') ? _("Device spundown, spinup to get information") : _("No self-tests logged on this disk");
    printf("<span class='big'>%s</span>", $msg);
  } elseif (strpos($result, "Completed") !== false) {
    $cls = (strpos($result, "failed") !== false) ? "red-text" : "green-text";
    printf("<span class='big %s'>%s</span>", $cls, _($result));
  } elseif (strpos($result, "Aborted") !== false || strpos($result, "Interrupted") !== false) {
    printf("<span class='big orange-text'>%s</span>", _($result));
  } elseif (strpos($result, "Failed") !== false) {
    printf("<span class='big red-text'>%s</span>", _($result));
  } else {
    printf("<span class='big red-text'>%s</span>", _('Errors occurred - Check SMART report'));
  }
  break;

case "selftest":
  echo shell_exec("smartctl -n standby -l selftest $type $device_path | awk 'NR>5'");
  break;

case "errorlog":
  echo shell_exec("smartctl -n standby -l error $type $device_path | awk 'NR>5'");
  break;
}
?>

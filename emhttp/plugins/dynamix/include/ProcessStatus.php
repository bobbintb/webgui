<?PHP
/* Copyright 2005-2025, Lime Technology
 * Copyright 2015-2025, Bergware International
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');

// add translations
$_SERVER['REQUEST_URI'] = '';
require_once "$docroot/webGui/include/Translations.php";

$name = $_POST['name'] ?? '';
$pid = false;

switch ($name) {
case 'crontab':
  $plugin = basename($_POST['plugin'] ?? '');
  $job = basename($_POST['job'] ?? '');
  if ($plugin && $job) {
    $pid = is_file("/boot/config/plugins/$plugin/$job.cron");
  }
  break;
case 'preclear_disk':
  $device = preg_replace('/[^a-z0-9]/i', '', $_POST['device'] ?? '');
  if ($device !== "") {
    $pid = exec(sprintf("ps -o pid,command --ppid 1 | awk -F/ %s", escapeshellarg("/$name .*$device$/{print $1;exit}")));
  }
  break;
case is_numeric($name):
  $port = (int)$name;
  $pid = exec(sprintf("lsof -i:%d -Pn | awk '/\(LISTEN\)/{print $2;exit}'", $port));
  break;
case 'pid':
  $plugin = basename($_POST['plugin'] ?? '');
  if ($plugin !== "") {
    $pid = is_file("/var/run/$plugin.pid");
  }
  break;
default:
  if ($name !== "") {
    $pid = exec("pidof -s -x " . escapeshellarg($name));
  }
  break;
}

$span = isset($_POST['update']) ? "" : "<span id='progress' class='status'>";
$_span = isset($_POST['update']) ? "" : "</span>";

if ($pid) {
  printf("%s%s:<span class='green'>%s</span>%s", $span, _('Status'), _('Running'), $_span);
} else {
  printf("%s%s:<span class='orange'>%s</span>%s", $span, _('Status'), _('Stopped'), $_span);
}
?>

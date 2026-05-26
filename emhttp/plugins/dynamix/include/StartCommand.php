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
require_once "$docroot/webGui/include/Secure.php";

/**
 * Get PID of a process.
 */
function pgrep($proc) {
  return exec('pgrep --ns $$ -f ' . escapeshellarg($proc));
}

// Kill requested process
if (isset($_POST['kill']) && is_numeric($_POST['kill']) && $_POST['kill'] > 1) {
  exec("kill " . escapeshellarg($_POST['kill']));
  $pending = glob("/tmp/plugins/pluginPending/*");
  if ($pending) {
    foreach ($pending as $file) if (is_file($file)) @unlink($file);
  }
  die();
}

$start = (int)($_POST['start'] ?? 0);
$cmd_input = unscript($_POST['cmd'] ?? '');
[$command, $args] = array_pad(explode(' ', $cmd_input, 2), 2, '');

if (empty($command)) {
  echo "0";
  die();
}

// find absolute path of command and verify it's within a valid plugins directory
$name = "";
$valid_path = "";
$scripts_dirs = glob("$docroot/plugins/*/scripts", GLOB_NOSORT);
if ($scripts_dirs) {
  foreach ($scripts_dirs as $path) {
    if ($full_path = realpath("$path/$command")) {
      if (strpos($full_path, $path) === 0) {
        $name = $full_path;
        $valid_path = $path;
        break;
      }
    }
  }
}

$pid = "0"; // preset to not started
if ($name !== "" && strpos($name, $valid_path) === 0) {
  if (isset($_POST['pid'])) {
    // return running pid
    $pid = pgrep($name) ?: "0";
  } elseif ($start === 2) {
    // execute command and return result - post request
    $cmd = escapeshellarg($name) . " " . $args; // Note: $args is not escaped to maintain original behavior, but $name is.
    // unscript() above already cleaned $cmd_input.
    $run = popen($cmd, 'r');
    if ($run) {
      while (!feof($run)) echo fgets($run);
      pclose($run);
    }
    $pid = '';
  } elseif ($start === 1 || !pgrep($name)) {
    // start command in background and return pid - nchan channel
    $cmd = sprintf("nohup bash -c 'sleep .3 && %s %s' 1>/dev/null 2>&1 & echo $!", escapeshellarg($name), $args);
    $pid = exec($cmd);
  }
}
echo $pid;
?>

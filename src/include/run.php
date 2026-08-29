<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');

$token = compose2unraid_csrf_token();
$arguments = compose2unraid_run_arguments($_GET, compose2unraid_base_path(), $token);
if (is_string($arguments)) {
    compose2unraid_refuse($arguments);
}
$theme = compose2unraid_theme();
$title = compose2unraid_run_title($_GET);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= compose2unraid_h($title) ?></title>
<link type="text/css" rel="stylesheet" href="/webGui/styles/default-fonts.css">
<link type="text/css" rel="stylesheet" href="/webGui/styles/default-color-palette.css">
<link type="text/css" rel="stylesheet" href="/webGui/styles/default-base.css">
<link type="text/css" rel="stylesheet" href="/webGui/styles/default-dynamix.css">
<link type="text/css" rel="stylesheet"
  href="/webGui/styles/themes/<?= compose2unraid_h($theme) ?>.css">
<style>
html, body { height: 100%; }
body {
  margin: 0; padding: 8px 12px; box-sizing: border-box; display: flex; flex-direction: column;
}
pre {
  flex: 1; min-height: 0; overflow: auto; margin: 0; padding: 8px 10px;
  white-space: pre-wrap; overflow-wrap: anywhere; font-size: 0.9em;
  background: rgba(128, 128, 128, 0.12); border-radius: 3px;
}
.done { margin: 10px 0 0; }
.actions { text-align: center; margin-top: 12px; }
</style>
<script>
var c2uLayers = {};
function c2uLine(line) {
  var output = document.getElementById('output');
  var layer = line.match(/^ ([0-9a-f]{12}) /);
  if (layer && c2uLayers[layer[1]]) {
    c2uLayers[layer[1]].textContent = line;
  } else {
    var node = document.createTextNode(line);
    output.appendChild(node);
    if (layer) { c2uLayers[layer[1]] = node; }
  }
  output.scrollTop = output.scrollHeight;
}
</script>
</head>
<body>
<pre id="output"></pre>
<?php
ob_implicit_flush(true);
while (ob_get_level() > 0) {
    ob_end_flush();
}
echo str_repeat(' ', 4096);
flush();

ignore_user_abort(true);
$command = 'exec setsid ' . escapeshellarg(COMPOSE2UNRAID_SCRIPTS_DIR . '/apply.sh');
foreach ($arguments as $argument) {
    $command .= ' ' . escapeshellarg($argument);
}
$pipes = [];
$process = proc_open($command . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
$exitCode = 1;
$stopped = false;
if (is_resource($process)) {
    $pid = (int) proc_get_status($process)['pid'];
    while (true) {
        $readable = [$pipes[1]];
        $write = null;
        $except = null;
        if (stream_select($readable, $write, $except, 1) > 0) {
            $line = fgets($pipes[1]);
            if ($line === false) {
                break;
            }
            echo '<script>c2uLine(' . json_encode($line, COMPOSE2UNRAID_JSON_IN_HTML) . ')</script>'
                . "\n";
        } else {
            echo "<!-- still running -->\n";
        }
        flush();
        if (connection_aborted()) {
            exec('kill -TERM -- -' . $pid);
            $stopped = true;
            break;
        }
    }
    fclose($pipes[1]);
    $exitCode = proc_close($process);
}
$verdict = match (true) {
    $stopped => 'Stopped, the window was closed.',
    $exitCode === 0 => 'Finished. Close this window to refresh the page.',
    default => 'Failed (exit code ' . $exitCode . '). The output above says why.',
};
?>
<p class="done <?= $exitCode === 0 ? 'green-text' : 'red-text' ?>"><?= $verdict ?></p>
<div class="actions"><input type="button" value="Done" onclick="parent.Shadowbox.close()"></div>
</body>
</html>

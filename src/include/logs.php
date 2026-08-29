<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');

if (!compose2unraid_token_matches($_GET, compose2unraid_csrf_token())) {
    compose2unraid_refuse(COMPOSE2UNRAID_TOKEN_REFUSAL);
}
$name = (string) ($_GET['name'] ?? '');
if (!compose2unraid_valid_container_name($name)) {
    compose2unraid_refuse('That is not a container name.');
}
$theme = compose2unraid_theme();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Log of <?= compose2unraid_h($name) ?></title>
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
pre { order: 1; }
.done { order: 2; margin: 10px 0 0; }
.actions { order: 3; text-align: center; margin-top: 12px; }
</style>
<script>
function c2uLine(line) {
  var output = document.getElementById('output');
  var follow = output.scrollHeight - output.scrollTop - output.clientHeight < 4;
  output.appendChild(document.createTextNode(line));
  if (follow) { output.scrollTop = output.scrollHeight; }
}
</script>
</head>
<body>
<div class="actions"><input type="button" value="Close" onclick="parent.Shadowbox.close()"></div>
<pre id="output"></pre>
<?php
compose2unraid_start_streaming();
$command = 'docker logs --tail 200 --follow ' . escapeshellarg($name);
[$exitCode, $stopped] = compose2unraid_stream($command, function (string $line): void {
    $encoded = json_encode($line, COMPOSE2UNRAID_JSON_IN_HTML);
    echo '<script>c2uLine(' . $encoded . ')</script>' . "\n";
});
$verdict = match (true) {
    $stopped => 'Stopped, the window was closed.',
    $exitCode === 0 => 'The log ended: the container is gone or was recreated.',
    default => 'docker logs failed (exit code ' . $exitCode . '). The output above says why.',
};
?>
<p class="done <?= $exitCode === 0 ? 'green-text' : 'red-text' ?>"><?= $verdict ?></p>
</body>
</html>

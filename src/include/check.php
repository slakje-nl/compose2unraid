<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');

if (!compose2unraid_token_matches($_GET, compose2unraid_csrf_token())) {
    compose2unraid_refuse(COMPOSE2UNRAID_TOKEN_REFUSAL);
}
if (!is_file(COMPOSE2UNRAID_DOCKER_CLIENT)) {
    compose2unraid_refuse('Unraid\'s DockerClient.php is not where this plugin expects it.');
}
require_once COMPOSE2UNRAID_DOCKER_CLIENT;

$theme = compose2unraid_theme();
$status = compose2unraid_status(false);
$images = compose2unraid_images_to_check($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Checking for updates</title>
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
function c2uLine(line) {
  var output = document.getElementById('output');
  output.appendChild(document.createTextNode(line));
  output.scrollTop = output.scrollHeight;
}
</script>
</head>
<body>
<pre id="output"></pre>
<?php
compose2unraid_start_streaming();

function compose2unraid_say(string $text): void
{
    $encoded = json_encode($text, COMPOSE2UNRAID_JSON_IN_HTML);
    echo '<script>c2uLine(' . $encoded . ')</script>' . "\n";
    flush();
}

if ($status['error'] !== null) {
    compose2unraid_say('The stacks could not be read: ' . $status['error'] . "\n");
}
if ($images === []) {
    compose2unraid_say("No container of a stack on disk exists yet, nothing to check.\n");
}
$update = new DockerUpdate();
$newer = 0;
$unknown = 0;
foreach ($images as $image) {
    compose2unraid_say($image . ': ');
    $verdict = 'unknown';
    try {
        $update->reloadUpdateStatus($image);
        $entry = compose2unraid_update_status()[DockerUtil::ensureImageTag($image)] ?? [];
        $verdict = match ($entry['status'] ?? '') {
            'false' => 'update ready',
            'true' => 'up to date',
            default => 'unknown, the registry did not answer',
        };
    } catch (Throwable $failure) {
        $verdict = 'unknown, ' . $failure->getMessage();
    }
    if ($verdict === 'update ready') {
        $newer++;
    } elseif ($verdict !== 'up to date') {
        $unknown++;
    }
    compose2unraid_say($verdict . "\n");
    if (connection_aborted()) {
        break;
    }
}
$summary = compose2unraid_check_summary(count($images), $newer, $unknown);
$colour = $newer > 0 ? 'orange-text' : 'green-text';
?>
<p class="done <?= $colour ?>"><?= compose2unraid_h($summary) ?></p>
<div class="actions"><input type="button" value="Done" onclick="parent.Shadowbox.close()"></div>
<script>parent.c2uRefresh(false)</script>
</body>
</html>

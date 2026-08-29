<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$stack = (string) ($_GET['stack'] ?? '');
if (!compose2unraid_valid_stack_name($stack)) {
    http_response_code(400);
    $stack = '';
}
$theme = compose2unraid_theme();
$dir = compose2unraid_base_path() . '/stacks/' . $stack;
$sections = [
    'Where it lives' => [
        ['Stack directory', $dir],
        ['Secrets file, optional', $dir . '/.env'],
    ],
    'After syncing new files' => [
        ['Apply changes', 'docker compose up -d --remove-orphans'],
        ['Recreate everything', 'docker compose up -d --force-recreate --remove-orphans'],
    ],
    'Images' => [
        ['Update images', 'docker compose pull && docker compose up -d --remove-orphans'],
    ],
    'Remove' => [
        ['Remove with volumes', 'docker compose -p ' . $stack . ' down --remove-orphans --volumes'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Commands for <?= compose2unraid_h($stack) ?></title>
<link type="text/css" rel="stylesheet" href="/webGui/styles/default-fonts.css">
<link type="text/css" rel="stylesheet" href="/webGui/styles/default-color-palette.css">
<link type="text/css" rel="stylesheet" href="/webGui/styles/default-base.css">
<link type="text/css" rel="stylesheet" href="/webGui/styles/default-dynamix.css">
<link type="text/css" rel="stylesheet"
  href="/webGui/styles/themes/<?= compose2unraid_h($theme) ?>.css">
<style>
body { margin: 0; padding: 8px 12px; box-sizing: border-box; }
p.lead { margin: 0 0 6px; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; padding: 14px 8px 4px; text-transform: uppercase; }
td { padding: 6px 8px; vertical-align: middle; border-top: 1px solid rgba(128, 128, 128, 0.25); }
td:first-child { width: 30%; }
td:last-child { width: 1%; }
code { display: block; overflow-wrap: anywhere; }
.actions { text-align: center; margin-top: 12px; }
</style>
<script>
function c2uCopy(button, text) {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text);
  } else {
    var scratch = document.createElement('textarea');
    scratch.value = text;
    scratch.setAttribute('readonly', '');
    scratch.style.position = 'fixed';
    scratch.style.opacity = '0';
    document.body.appendChild(scratch);
    scratch.focus();
    scratch.select();
    document.execCommand('copy');
    document.body.removeChild(scratch);
  }
  button.value = 'Copied';
}
</script>
</head>
<body>
<?php if ($stack === ''): ?>
<p class="lead red-text">That is not a stack name.</p>
<?php else: ?>
<p class="lead">
  Run these in a terminal on the box, from the stack directory unless the command names the
  project itself.
</p>
<table>
<?php foreach ($sections as $heading => $rows): ?>
  <tr><th colspan="3"><?= compose2unraid_h($heading) ?></th></tr>
  <?php foreach ($rows as [$what, $command]): ?>
  <?php $quoted = json_encode($command, COMPOSE2UNRAID_JSON_IN_HTML) ?>
  <tr>
    <td><?= compose2unraid_h($what) ?></td>
    <td><code><?= compose2unraid_h($command) ?></code></td>
    <td>
      <input type="button" value="Copy" onclick="c2uCopy(this, <?= compose2unraid_h($quoted) ?>)">
    </td>
  </tr>
  <?php endforeach ?>
<?php endforeach ?>
</table>
<?php endif ?>
<div class="actions"><input type="button" value="Done" onclick="parent.Shadowbox.close()"></div>
</body>
</html>

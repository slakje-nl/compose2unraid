<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$withDrift = ($_GET['drift'] ?? '1') !== '0';
$status = compose2unraid_status($withDrift);
$byStack = compose2unraid_containers_by_stack($status['containers']);
$stats = compose2unraid_stats_by_id($status['stats'], (int) ($status['cpus'] ?? 1));
$updateStatus = compose2unraid_update_status();
$basePath = compose2unraid_base_path();
$h = 'compose2unraid_h';
$columns = '<colgroup>';
foreach (['name', 'stack', 'health', 'version', 'cpu', 'memory', 'uptime'] as $column) {
    $columns .= '<col class="c2u-col-' . $column . '">';
}
$columns .= '</colgroup>';
?>
<div id="c2u-content">

<?php if ($status['error'] !== null): ?>
  <p class="red-text">The stack status could not be read: <?= $h($status['error']) ?></p>
<?php endif ?>

<?php if ($status['stacks'] === []): ?>
  <p>
    No stacks yet. Put one directory per stack, each with a compose file, under
    <code><?= $h($basePath) ?>/stacks/</code>.
  </p>
<?php endif ?>

<?php foreach ($status['stacks'] as $entry): ?>
  <?php
  $stack = $entry['name'];
  $rows = compose2unraid_rows($entry, $byStack[$stack] ?? []);
  $flagged = [];
  foreach ($byStack[$stack] ?? [] as $container) {
      if (compose2unraid_image_update($container, $updateStatus) === true) {
          $flagged[] = $container['service'];
      }
  }
  $files = $entry['drift'] !== 'gone';
  $containers = count($byStack[$stack] ?? []);
  $running = count(array_filter(
      $byStack[$stack] ?? [],
      fn(array $container): bool => $container['state'] === 'running'
  ));
  ?>
  <table class="tablesorter c2u-stack">
    <?= $columns ?>
    <thead>
      <tr>
        <th class="c2u-stack-name">
          <?= $h($stack) ?>
          <?php if ($entry['drift'] === 'broken' && $rows === []): ?>
            <?php [$label, $hint] = compose2unraid_problem($entry, $basePath) ?>
            <span class="c2u-note red-text" title="<?= $h($hint) ?>">
              <i class="fa fa-exclamation-triangle"></i> <?= $h($label) ?>
            </span>
          <?php endif ?>
          <?php if ($entry['drift'] === 'unknown' && $rows === []): ?>
            <span class="c2u-note"><?= compose2unraid_not_checked() ?></span>
          <?php endif ?>
          <?php if ($rows === []): ?>
            <span class="c2u-note">
              <a href="#" class="c2u-commands" data-stack="<?= $h($stack) ?>">stack commands</a>
            </span>
          <?php endif ?>
        </th>
        <th>Stack</th><th>Health</th><th>Version</th><th>CPU</th><th>Memory</th><th>Uptime</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="7" class="grey-text">No services yet.</td></tr>
    <?php endif ?>
    <?php foreach ($rows as $row): ?>
      <?php
      $container = $row['container'];
      $name = $row['name'];
      $state = $container['state'] ?? '';
      $update = $container === null ? null : compose2unraid_image_update($container, $updateStatus);
      $usage = $container === null ? null : compose2unraid_container_usage($container, $stats);
      ?>
      <tr>
        <td>
          <span class="outer <?= $state === 'running' ? 'started' : 'stopped' ?>">
            <span class="hand" id="<?= $h($row['id']) ?>"
              onclick="c2uMenu(this)" data-stack="<?= $h($stack) ?>"
              data-service="<?= $h($row['service']) ?>"
              data-state="<?= $h($state) ?>"
              data-update="<?= $update === true ? '1' : '' ?>"
              data-flagged="<?= $h(implode(' ', $flagged)) ?>"
              data-files="<?= $files ? '1' : '' ?>" data-drift="<?= $h($entry['drift']) ?>"
              data-containers="<?= $containers ?>" data-running="<?= $running ?>"
              data-name="<?= $h($name) ?>">
              <?= compose2unraid_icon($name, $row['labels']) ?>
            </span>
            <span class="inner">
              <span class="appname"><?= $h($name) ?></span><br>
              <?= compose2unraid_container_state_line($state) ?>
            </span>
          </span>
        </td>
        <td><?= compose2unraid_stack_line($entry, $row, $basePath) ?></td>
        <td>
          <?php if ($container !== null): ?>
            <?= compose2unraid_health_line($state, $container['health']) ?>
          <?php endif ?>
        </td>
        <td>
          <?php if ($container !== null): ?>
            <?= compose2unraid_container_version($container['image']) ?>
          <?php endif ?>
          <?php if ($update === true): ?>
            <br><?= compose2unraid_badge('orange', 'bolt', 'update ready') ?>
          <?php endif ?>
        </td>
        <td class="c2u-meter">
          <?php if ($usage !== null): ?>
            <?= compose2unraid_meter($usage['cpu'], $usage['cpu_percent']) ?>
          <?php endif ?>
        </td>
        <td class="c2u-meter">
          <?php if ($usage !== null): ?>
            <?= compose2unraid_meter($usage['memory'], $usage['memory_percent']) ?>
          <?php endif ?>
        </td>
        <td>
          <?php if ($state === 'running'): ?>
            <?= $h(compose2unraid_age($container['started'])) ?>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
<?php endforeach ?>

</div>

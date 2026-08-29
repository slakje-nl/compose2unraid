<?php

declare(strict_types=1);

$var = ['csrf_token' => 'token'];
$failures = [];

function expect(string $html, string $needle, string $why): void
{
    global $failures;
    if (!str_contains($html, $needle)) {
        $failures[] = $why . ' (missing: ' . $needle . ')';
    }
}

function refuse(string $html, string $pattern, string $why): void
{
    global $failures;
    if (preg_match($pattern, $html) === 1) {
        $failures[] = $why . ' (unexpected: ' . $pattern . ')';
    }
}

function render(string $file): string
{
    global $var;
    ob_start();
    if (str_ends_with($file, '.page')) {
        $body = explode("\n---\n", (string) file_get_contents($file), 2)[1];
        eval('?>' . $body);
    } else {
        include $file;
    }

    return ob_get_clean();
}

$page = render('/usr/local/emhttp/plugins/compose2unraid/Compose2Unraid.page');
expect($page, 'id="c2u-content"', 'the page has the div the fragment replaces');
expect($page, 'Reading the stacks', 'the page shows a placeholder until the first fetch');
refuse($page, '/<table class="tablesorter c2u-stack"/', 'the page does not run status.sh itself');
expect($page, 'name="csrf_token" value="token"', 'the run form carries the csrf token');
expect($page, 'id="c2u-help"', 'the commands dialog is there');
expect($page, 'id="c2u-run-title"', 'the run dialog has a title like the popup');
expect($page, 'value="Refresh stacks" onclick="c2uRefreshStacks(this)"', 'a refresh stacks button sits below the tables');
expect($page, 'value="Check for updates"', 'a check for updates button sits below the tables');
expect($page, "setTimeout(function () { refresh(false); }, 5000)", 'container state ticks every five seconds without drift');
expect($page, "(drift ? '' : '?drift=0')", 'a tick asks the fragment to skip the dry run');
expect($page, "refresh(true);\n})();", 'the first load checks drift');
expect($page, "'/plugins/dynamix.docker.manager/include/DockerUpdate.php'", 'it calls what the Docker tab calls');
expect($page, "context.attach('#' + icon.id, opts)", 'the menu is the Unraid context menu');
expect($page, "context.settings({right: false, above: 'auto'})", 'the menu opens above the icon when it would not fit below');
expect($page, "openTerminal('docker', data.name, '.log')", 'logs open in Unraid\'s own log window');
expect($page, '#c2u-help th { text-align: left;', 'the commands popup keeps its own table style');
expect($page, "getElementById('c2u-help').addEventListener('close', function () { c2uRefresh(false); })", 'closing the commands popup refreshes without a dry run');
expect($page, "getElementById('c2u-run').addEventListener('close'", 'closing the run dialog refreshes, however it is closed');
expect($page, "if (!response.ok) { throw new Error(", 'a failed fetch keeps the table it has instead of showing the error page');

$fragment = render('/usr/local/emhttp/plugins/compose2unraid/include/stacks.php');
expect($fragment, '<th>Stack</th><th>Health</th>', 'a stack column sits before health');
$stackCell = fn(string $colour, string $icon, string $text): string =>
    '<td><span class="' . $colour . '-text"><i class="fa fa-' . $icon . '"></i> ' . $text . '</span></td>';
expect($fragment, $stackCell('orange', 'bolt', 'changed'), 'a changed service is orange');
expect($fragment, $stackCell('green', 'check', 'up to date'), 'a service of a stack in sync is green');
if (substr_count($fragment, 'up to date</span>') !== 1) {
    $failures[] = 'only the stack in sync says up to date, a changed stack says so on every row';
}
expect($fragment, $stackCell('grey', 'circle-o', 'not deployed'), 'a service with no container is grey');
expect($fragment, $stackCell('red', 'times-circle', 'no files'), 'a container without files is red');
expect($fragment, $stackCell('orange', 'bolt', 'removed on disk'), 'a container whose service left the file says so');
expect($fragment, '<span class="appname">beta-old-1</span>', 'that container is still listed');
refuse($fragment, '/not in stacks|not started yet|in sync|changed: app/', 'the header carries no state, the stack column does');
expect($fragment, 'title="The compose file needs /tmp/compose2unraid/stacks/noenv/.env. Create it, even empty.">' . "\n" . '              <i class="fa fa-exclamation-triangle"></i> missing .env', 'a missing .env is named, with the path on hover');
expect($fragment, 'update ready', 'a container Unraid flagged shows the badge');
expect($fragment, '<i class="fa fa-bolt"></i> update ready</span>', 'update ready is a plain badge');
refuse($fragment, '/<a[^>]*>[^<]*<i class="fa fa-bolt"><\/i> update ready/', 'update ready is not a link');
refuse($fragment, '/class="c2u-run"/', 'the header carries no actions, the icon menu does');
$icon = 'id="c2u-aaaaaaaaaaaa"' . "\n" . '              onclick="c2uMenu(this)" data-stack="alpha"' . "\n"
    . '              data-service="app"' . "\n" . '              data-state="running"' . "\n"
    . '              data-update="1"' . "\n" . '              data-flagged="app"' . "\n"
    . '              data-files="1"';
expect($fragment, $icon, 'a container icon opens the Unraid context menu with what it needs');
expect($fragment, 'data-name="alpha-app-1"', 'the icon knows the container name for the logs');
expect($fragment, 'data-files="1" data-drift="changed"' . "\n" . '              data-containers="2" data-running="2"', 'the icon knows the stack drift, its containers and how many run');
expect($fragment, 'data-containers="1" data-running="0"', 'a stack whose containers are all stopped says so');
expect($fragment, 'data-stack="gamma"' . "\n" . '              data-service="web"' . "\n" . '              data-state=""' . "\n" . '              data-update=""' . "\n" . '              data-flagged=""' . "\n" . '              data-files="1" data-drift="new"' . "\n" . '              data-containers="0"', 'a stack with no containers offers no stack start or stop');
expect($fragment, 'id="c2u-cccccccccccc"' . "\n" . '              onclick="c2uMenu(this)" data-stack="gone"', 'a container without files still gets the menu');
expect($fragment, 'data-stack="gone"' . "\n" . '              data-service="app"' . "\n" . '              data-state="exited"' . "\n" . '              data-update=""' . "\n" . '              data-flagged=""' . "\n" . '              data-files=""', 'without files the menu knows to offer commands only');
$placeholder = 'id="c2u-gamma-web"' . "\n" . '              onclick="c2uMenu(this)" data-stack="gamma"' . "\n"
    . '              data-service="web"' . "\n" . '              data-state=""';
expect($fragment, $placeholder, 'a service on disk with no container gets a row and a menu');
expect($fragment, '<span class="appname">gamma-web-1</span>', 'the row is named like the container will be');
expect($fragment, '<span class="appname">my-cache</span>', 'a container_name is used when set');
expect($fragment, 'src="https://example.com/web.png"', 'the icon label from the compose file is shown');
expect($fragment, 'not created', 'a service without a container says so');
expect($fragment, '<span class="appname">alpha-db-1</span>', 'a service added on disk shows next to the running ones');
expect($fragment, 'data-stack="noenv"' . "\n" . '                data-base="/tmp/compose2unraid">stack commands', 'a stack with nothing to click keeps the commands link');
refuse($fragment, '/c2u-commands" data-stack="(alpha|gamma)"/', 'a stack with rows has the commands in the menu');
if (substr_count($fragment, 'No services yet.') !== 1) {
    $failures[] = 'only the stack with nothing to show says No services yet';
}
expect($fragment, '<td><span class="red-text" title="yaml: line 3: did not find expected key"><i class="fa fa-times-circle"></i> compose error</span></td>', 'every row of a broken stack says compose error, with the message on hover');
refuse($fragment, '/torn-app-1.*removed on disk/s', 'a broken stack does not call its containers removed');
refuse($fragment, '/c2u-note red-text" title="yaml/', 'a broken stack with rows has no header label');
expect($fragment, 'title="The compose file needs /tmp/compose2unraid/stacks/noenv/.env. Create it, even empty.">' . "\n" . '              <i class="fa fa-exclamation-triangle"></i> missing .env', 'a broken stack without rows keeps the label in its header');
$cpuBar = '<span class="c2u-usage">0.19%<span class="c2u-bar"><span style="width: 0.2%"';
expect($fragment, $cpuBar, 'cpu is the share of the whole box, with a bar as wide as its text');
$memoryBar = '31.2 MiB / 125.70 GiB<span class="c2u-bar"><span style="width: 0.0%"';
expect($fragment, $memoryBar, 'memory with a bar');
refuse($fragment, '/loading\.\.\./', 'no placeholder once the stats are in');
expect($fragment, '<code>@0123456789</code>', 'a pinned image shows its short digest');
expect($fragment, 'question.png', 'a container without a cached icon gets the question mark');
refuse($fragment, '/Uptime:|Created:/', 'the uptime cell is the duration alone');
expect($fragment, '<span class="red-text"><i class="fa fa-times-circle"></i> stopped</span>', 'a container that is not running is stopped, whatever its healthcheck');
expect($fragment, '<span class="grey-text"><i class="fa fa-minus-circle"></i> n/a</span>', 'a running container without a healthcheck is n/a');
expect($fragment, '<span class="green-text"><i class="fa fa-check-circle"></i> healthy</span>', 'a healthy container is healthy');
refuse($fragment, '/not checked/', 'after a check every stack on disk has a state');
$_GET = ['drift' => '0'];
$quick = render('/usr/local/emhttp/plugins/compose2unraid/include/stacks.php');
$_GET = [];
$notChecked = '<span class="grey-text" title="This stack appeared after the last check. Click Refresh stacks."><i class="fa fa-question-circle"></i> not checked</span>';
expect($quick, '<td>' . $notChecked . '</td>', 'a container of a stack not checked yet says so on its row');
expect($quick, '<span class="c2u-note">' . $notChecked . '</span>', 'a stack not checked yet and without rows says so in its header');
expect($quick, 'data-drift="unknown"', 'the menu knows the stack was not checked');
expect($quick, '<span class="appname">alpha-app-1</span>', 'a tick has the stacks');

$run = (string) file_get_contents('/usr/local/emhttp/plugins/compose2unraid/include/run.php');
$defined = strpos($run, 'function c2uLine(');
expect($run, "'exec setsid '", 'the run gets its own process group');
expect($run, "exec('kill -TERM -- -' . \$pid)", 'closing the dialog kills that group');
$used = strpos($run, '<pre id="output">');
if ($defined === false || $used === false || $defined > $used) {
    $failures[] = 'run.php must define c2uLine before the streamed lines call it';
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "render ok\n";

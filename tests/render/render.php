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

$page = render('/usr/local/emhttp/plugins/compose2unraid/Compose.page');
expect($page, 'id="c2u-content"', 'the page has the div the fragment replaces');
expect($page, 'Reading the stacks', 'the page shows a placeholder until the first fetch');
refuse($page, '/<table class="tablesorter c2u-stack"/', 'the page does not run status.sh itself');
expect($page, "openBox(url, c2uTitle(action, stack, services), 600, 900, true, 'c2uRunDone')", 'a run opens in Unraid\'s own dialog and refreshes with drift when it closes');
expect($page, "'/plugins/compose2unraid/include/run.php?action=' + encodeURIComponent(action)", 'the run dialog loads run.php');
expect($page, "+ '&csrf_token=' + csrf_token;", 'the run carries the page\'s csrf token');
expect($page, "openBox('/plugins/compose2unraid/include/commands.php?stack=' + encodeURIComponent(stack),", 'the commands popup is Unraid\'s own dialog around commands.php');
expect($page, "600, 900, true, 'c2uCommandsDone')", 'closing the commands popup refreshes without a dry run');
refuse($page, '/<dialog|showModal|c2u-help|c2u-run-form/', 'the page has no dialogs of its own');
expect($page, 'value="Refresh stacks" onclick="c2uRefreshStacks(this)"', 'a refresh stacks button sits below the tables');
expect($page, 'value="Check for updates"', 'a check for updates button sits below the tables');
expect($page, "setTimeout(function () { refresh(false); }, 5000)", 'container state ticks every five seconds without drift');
expect($page, "(drift ? '' : '?drift=0')", 'a tick asks the fragment to skip the dry run');
expect($page, "refresh(true);\n})();", 'the first load checks drift');
expect($page, "context.attach('#' + icon.id, opts)", 'the menu is the Unraid context menu');
expect($page, "context.settings({right: false, above: 'auto'})", 'the menu opens above the icon when it would not fit below');
expect($page, "openTerminal('docker', data.name, '.log')", 'logs open in Unraid\'s own log window');
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
expect($fragment, '<a href="#" class="c2u-commands" data-stack="noenv">stack commands</a>', 'a stack with nothing to click keeps the commands link');
refuse($fragment, '/data-base=/', 'the base path is not the page\'s business, commands.php knows it');
refuse($fragment, '/c2u-commands" data-stack="(alpha|gamma)"/', 'a stack with rows has the commands in the menu');
if (substr_count($fragment, 'No services yet.') !== 1) {
    $failures[] = 'only the stack with nothing to show says No services yet';
}
expect($fragment, '<td><span class="red-text"><i class="fa fa-times-circle"></i> compose error</span></td>', 'every row of a broken stack says compose error');
refuse($fragment, '/title="(yaml|The compose file)/', 'the message is printed, not hidden in a tooltip');
refuse($fragment, '/torn-app-1.*removed on disk/s', 'a broken stack does not call its containers removed');
expect($fragment, 'torn-app-1</span>', 'the broken stack lists its container');
if (preg_match('/torn-app-1.*?<td colspan="7" class="red-text">\s*<i class="fa fa-exclamation-triangle"><\/i> compose error: yaml: line 3: did not find expected key\s*<\/td>/s', $fragment) !== 1) {
    $failures[] = 'a broken stack prints the compose error below its last container';
}
expect($fragment, '<i class="fa fa-exclamation-triangle"></i> missing .env: The compose file needs /tmp/compose2unraid/stacks/noenv/.env. Create it, even empty.', 'a missing .env is printed in full below the rows');
refuse($fragment, '/torn\s*<span class="c2u-note red-text"/', 'a broken stack with rows has no header label');
expect($fragment, '<span class="c2u-note red-text">' . "\n" . '              <i class="fa fa-exclamation-triangle"></i> missing .env', 'a broken stack without rows keeps the label in its header');
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
expect($run, 'json_encode($line, COMPOSE2UNRAID_JSON_IN_HTML)', 'a streamed line can never open or close a tag');
expect($run, 'compose2unraid_run_arguments($_GET, compose2unraid_base_path(), $token)', 'run.php accepts nothing the validation did not pass');
expect($run, 'onclick="parent.Shadowbox.close()"', 'the Done button closes Unraid\'s dialog');
$used = strpos($run, '<pre id="output">');
if ($defined === false || $used === false || $defined > $used) {
    $failures[] = 'run.php must define c2uLine before the streamed lines call it';
}

mkdir('/tmp/compose2unraid/stacks/alpha', 0755, true);
$base = '/tmp/compose2unraid';
$request = fn(array $fields): array|string =>
    compose2unraid_run_arguments($fields + ['csrf_token' => 'token'], $base, 'token');
$refusals = [
    'a missing token' => compose2unraid_run_arguments(['action' => 'apply', 'stack' => 'alpha'], $base, 'token'),
    'a wrong token' => compose2unraid_run_arguments(['action' => 'apply', 'stack' => 'alpha', 'csrf_token' => 'other'], $base, 'token'),
    'a box without a token' => compose2unraid_run_arguments(['action' => 'apply', 'stack' => 'alpha', 'csrf_token' => ''], $base, ''),
    'an unknown action' => $request(['action' => 'down', 'stack' => 'alpha']),
    'a stack that is not on disk' => $request(['action' => 'apply', 'stack' => 'beta']),
    'a stack name with a path in it' => $request(['action' => 'apply', 'stack' => '../alpha']),
    'a service name with a space' => $request(['action' => 'stop', 'stack' => 'alpha', 'services' => 'app; rm']),
    'an update without services' => $request(['action' => 'update', 'stack' => 'alpha']),
];
foreach ($refusals as $case => $result) {
    if (!is_string($result)) {
        $failures[] = 'run.php must refuse ' . $case;
    }
}
$accepted = [
    'apply' => [['action' => 'apply', 'stack' => 'alpha'], ['alpha']],
    'update' => [['action' => 'update', 'stack' => 'alpha', 'services' => 'app  db'], ['alpha', '--pull', 'app', 'db']],
    'stop' => [['action' => 'stop', 'stack' => 'alpha'], ['alpha', '--stop']],
    'diff' => [['action' => 'diff', 'stack' => 'alpha'], ['alpha', '--diff']],
];
foreach ($accepted as $case => [$fields, $arguments]) {
    if ($request($fields) !== $arguments) {
        $failures[] = 'run.php must pass ' . $case . ' as ' . implode(' ', $arguments);
    }
}
if (compose2unraid_run_title(['action' => 'update', 'stack' => 'alpha', 'services' => 'app db']) !== 'Updating app, db in alpha') {
    $failures[] = 'the run title names the services and the stack';
}

$_GET = ['stack' => 'alpha'];
$commands = render('/usr/local/emhttp/plugins/compose2unraid/include/commands.php');
$_GET = [];
expect($commands, '<code>/tmp/compose2unraid/stacks/alpha/.env</code>', 'the commands popup names the secrets file');
expect($commands, '<code>docker compose -p alpha down --remove-orphans --volumes</code>', 'the commands popup writes out down');
expect($commands, 'onclick="c2uCopy(this, &quot;docker compose up -d --remove-orphans&quot;)"', 'every command has a copy button');
expect($commands, 'onclick="parent.Shadowbox.close()"', 'the popup closes Unraid\'s dialog');
function stream(string $file, array $query): string
{
    $script = '$_GET = ' . var_export($query, true) . '; include ' . var_export($file, true) . ';';

    return (string) shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1');
}

$check = stream('/usr/local/emhttp/plugins/compose2unraid/include/check.php', ['csrf_token' => 'token']);
$refusedCheck = stream('/usr/local/emhttp/plugins/compose2unraid/include/check.php', ['csrf_token' => 'other']);
expect($refusedCheck, 'does not carry the page', 'a check without the page\'s token is refused');
refuse($refusedCheck, '/c2uLine/', 'a refused check checks nothing');
expect($check, 'c2uLine("example\/alpha:1.2: ")</script>', 'the check names each image before asking the registry');
expect($check, 'c2uLine("example\/alpha:1.2: ")</script>' . "\n" . '<script>c2uLine("update ready\n")', 'an image Unraid found newer says update ready');
expect($check, 'c2uLine("example\/beta: ")</script>' . "\n" . '<script>c2uLine("up to date\n")', 'an image at its remote digest is up to date');
expect($check, 'c2uLine("example\/torn: ")</script>' . "\n" . '<script>c2uLine("unknown, no route to host\n")', 'a registry failure is shown, not fatal');
expect($check, 'c2uLine("example\/delta:2: ")</script>' . "\n" . '<script>c2uLine("unknown, the registry did not answer\n")', 'an image with no verdict is unknown');
refuse($check, '#example\\\\/gone#', 'a stack without files is not checked');
expect($check, '<p class="done orange-text">1 of 5 images has a newer version. 3 could not be checked.</p>', 'the summary counts newer and unchecked images');
expect($check, 'onclick="parent.Shadowbox.close()"', 'the check closes Unraid\'s dialog');
if (compose2unraid_check_summary(1, 1, 0) !== '1 of 1 image has a newer version.') {
    $failures[] = 'the summary reads well for one image';
}
if (compose2unraid_images_to_check(['stacks' => [['name' => 'a', 'drift' => 'gone']], 'containers' => [['stack' => 'a', 'image' => 'x']]]) !== []) {
    $failures[] = 'a gone stack contributes no image';
}

$_GET = ['stack' => '../etc'];
$refused = render('/usr/local/emhttp/plugins/compose2unraid/include/commands.php');
$_GET = [];
expect($refused, 'That is not a stack name.', 'a bad stack name gets no commands');
refuse($refused, '/docker compose/', 'a bad stack name gets no commands at all');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "render ok\n";

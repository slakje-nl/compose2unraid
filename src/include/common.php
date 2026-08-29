<?php

declare(strict_types=1);

const COMPOSE2UNRAID_PLUGIN_DIR = '/usr/local/emhttp/plugins/compose2unraid';
const COMPOSE2UNRAID_SCRIPTS_DIR = COMPOSE2UNRAID_PLUGIN_DIR . '/scripts';
const COMPOSE2UNRAID_UPDATE_STATUS = '/var/lib/docker/unraid-update-status.json';
const COMPOSE2UNRAID_ICON_DIR = '/var/lib/docker/unraid/images';
const COMPOSE2UNRAID_ICON_URL = '/state/plugins/dynamix.docker.manager/images';
const COMPOSE2UNRAID_QUESTION_ICON = '/plugins/dynamix.docker.manager/images/question.png';
const COMPOSE2UNRAID_VAR_INI = '/var/local/emhttp/var.ini';
const COMPOSE2UNRAID_TOKEN_REFUSAL =
    'This request does not carry the page\'s token. Reload the page and try again.';
const COMPOSE2UNRAID_DOCKER_CLIENT =
    '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
const COMPOSE2UNRAID_JSON_IN_HTML = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
const COMPOSE2UNRAID_VERBS = [
    'apply' => 'Syncing', 'update' => 'Updating', 'start' => 'Starting', 'stop' => 'Stopping',
    'restart' => 'Restarting', 'diff' => 'Changes for', 'recreate' => 'Recreating',
];

if (!function_exists('parse_plugin_cfg')) {
    require_once '/usr/local/emhttp/plugins/dynamix/include/Wrappers.php';
}

function compose2unraid_valid_stack_name(string $name): bool
{
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) === 1;
}

function compose2unraid_words(string $words): array
{
    return array_values(array_filter(explode(' ', $words), fn(string $word): bool => $word !== ''));
}

function compose2unraid_config(): array
{
    return parse_plugin_cfg('compose2unraid');
}

function compose2unraid_base_path(): string
{
    return rtrim(compose2unraid_config()['BASE_PATH'], '/');
}

function compose2unraid_refuse(string $message): never
{
    http_response_code(400);
    echo '<!DOCTYPE html><meta charset="utf-8"><p>' . compose2unraid_h($message) . '</p>';
    exit;
}

function compose2unraid_token_matches(array $request, string $token): bool
{
    return $token !== '' && hash_equals($token, (string) ($request['csrf_token'] ?? ''));
}

function compose2unraid_images_to_check(array $status): array
{
    $onDisk = [];
    foreach ($status['stacks'] as $entry) {
        if ($entry['drift'] !== 'gone') {
            $onDisk[$entry['name']] = true;
        }
    }
    $images = [];
    foreach ($status['containers'] as $container) {
        if (isset($onDisk[$container['stack']])) {
            $images[$container['image']] = true;
        }
    }
    ksort($images);

    return array_keys($images);
}

function compose2unraid_check_summary(int $total, int $newer, int $unknown): string
{
    $summary = $newer . ' of ' . $total . ' image' . ($total === 1 ? '' : 's')
        . ($newer === 1 ? ' has' : ' have') . ' a newer version.';
    if ($unknown > 0) {
        $summary .= ' ' . $unknown . ' could not be checked.';
    }

    return $summary;
}

function compose2unraid_csrf_token(): string
{
    $var = is_file(COMPOSE2UNRAID_VAR_INI) ? parse_ini_file(COMPOSE2UNRAID_VAR_INI) : [];

    return is_array($var) ? (string) ($var['csrf_token'] ?? '') : '';
}

function compose2unraid_run_arguments(array $request, string $basePath, string $token): array|string
{
    if (!compose2unraid_token_matches($request, $token)) {
        return COMPOSE2UNRAID_TOKEN_REFUSAL;
    }
    $action = (string) ($request['action'] ?? '');
    if (!isset(COMPOSE2UNRAID_VERBS[$action])) {
        return 'Unknown action.';
    }
    $stack = (string) ($request['stack'] ?? '');
    if (!compose2unraid_valid_stack_name($stack) || !is_dir($basePath . '/stacks/' . $stack)) {
        return 'That is not one of your stacks.';
    }
    $services = compose2unraid_words((string) ($request['services'] ?? ''));
    foreach ($services as $service) {
        if (!compose2unraid_valid_stack_name($service)) {
            return 'That is not a valid service name.';
        }
    }
    if ($action === 'update' && $services === []) {
        return 'Nothing to update.';
    }
    $option = $action === 'update' ? '--pull' : '--' . $action;

    return $action === 'apply' ? [$stack] : [$stack, $option, ...$services];
}

function compose2unraid_run_title(array $request): string
{
    $verb = COMPOSE2UNRAID_VERBS[(string) ($request['action'] ?? '')] ?? '';
    $stack = (string) ($request['stack'] ?? '');
    $services = compose2unraid_words((string) ($request['services'] ?? ''));

    return $services === []
        ? $verb . ' ' . $stack
        : $verb . ' ' . implode(', ', $services) . ' in ' . $stack;
}

function compose2unraid_theme(): string
{
    $display = parse_plugin_cfg('dynamix', true)['display'] ?? [];

    return preg_match('/^[a-z]+$/', $display['theme'] ?? '') === 1 ? $display['theme'] : 'white';
}

function compose2unraid_run_script(string $script, string ...$arguments): array
{
    $command = escapeshellarg(COMPOSE2UNRAID_SCRIPTS_DIR . '/' . $script);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return [1, '', $script . ' could not be started'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, trim($stdout), trim($stderr)];
}

function compose2unraid_empty_status(string $error): array
{
    return [
        'error' => $error,
        'stacks' => [],
        'containers' => [],
        'stats' => [],
        'cpus' => 1,
    ];
}

function compose2unraid_status(bool $withDrift): array
{
    $arguments = $withDrift ? [] : ['--without-drift'];
    [$exitCode, $output, $errors] = compose2unraid_run_script('status.sh', ...$arguments);
    if ($exitCode !== 0) {
        return compose2unraid_empty_status(trim($errors . "\n" . $output));
    }

    $status = json_decode($output, true);
    if (!is_array($status)) {
        return compose2unraid_empty_status('status.sh did not return JSON: ' . $errors);
    }

    $status['error'] = null;

    return $status;
}

function compose2unraid_image_key(string $image): string
{
    $key = (string) preg_replace('#^docker\.io/#', '', $image);
    if (!str_contains($key, '/')) {
        $key = 'library/' . $key;
    }
    $lastSegment = substr($key, (int) strrpos($key, '/'));
    if (!str_contains($lastSegment, ':') && !str_contains($key, '@')) {
        $key .= ':latest';
    }

    return $key;
}

function compose2unraid_update_status(): array
{
    if (!is_file(COMPOSE2UNRAID_UPDATE_STATUS)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents(COMPOSE2UNRAID_UPDATE_STATUS), true);

    return is_array($decoded) ? $decoded : [];
}

function compose2unraid_image_update(array $container, array $updateStatus): ?bool
{
    $entry = $updateStatus[compose2unraid_image_key($container['image'])] ?? null;
    if ($entry === null) {
        return null;
    }
    if (in_array($entry['remote'] ?? '', $container['digests'] ?? [], true)) {
        return false;
    }

    return match ($entry['status'] ?? '') {
        'false' => true,
        'true' => false,
        default => null,
    };
}

function compose2unraid_containers_by_stack(array $containers): array
{
    $byStack = [];
    foreach ($containers as $container) {
        $byStack[$container['stack']][] = $container;
    }
    foreach ($byStack as &$list) {
        usort($list, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    }

    return $byStack;
}

function compose2unraid_age(string $timestamp): string
{
    $seconds = time() - strtotime($timestamp);
    if ($seconds < 0) {
        return 'just now';
    }
    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$unit, $name]) {
        if ($seconds >= $unit) {
            $count = intdiv($seconds, $unit);

            return $count . ' ' . $name . ($count === 1 ? '' : 's');
        }
    }

    return $seconds . ' second' . ($seconds === 1 ? '' : 's');
}

function compose2unraid_h(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function compose2unraid_badge(string $colour, string $icon, string $text, string $hint = ''): string
{
    $title = $hint === '' ? '' : ' title="' . compose2unraid_h($hint) . '"';

    return '<span class="' . $colour . '-text"' . $title . '><i class="fa fa-' . $icon . '"></i> '
        . compose2unraid_h($text) . '</span>';
}

function compose2unraid_state_icon(string $icon, string $colour, string $text): string
{
    return '<i class="fa fa-' . $icon . ' ' . $colour . '-text"></i><span class="state">'
        . compose2unraid_h($text) . '</span>';
}

function compose2unraid_container_state_line(string $state): string
{
    return match ($state) {
        'running' => compose2unraid_state_icon('play started', 'green', 'started'),
        'paused' => compose2unraid_state_icon('pause paused', 'orange', 'paused'),
        '' => compose2unraid_state_icon('square stopped', 'grey', 'not created'),
        default => compose2unraid_state_icon('square stopped', 'red', $state),
    };
}

function compose2unraid_problem(array $entry, string $basePath): array
{
    if ($entry['error'] === 'missing .env') {
        $path = $basePath . '/stacks/' . $entry['name'] . '/.env';

        return ['missing .env', 'The compose file needs ' . $path . '. Create it, even empty.'];
    }

    return ['compose error', (string) $entry['error']];
}

function compose2unraid_not_checked(): string
{
    return compose2unraid_badge('grey', 'question-circle', 'not checked',
        'This stack appeared after the last check. Click Refresh stacks.');
}

function compose2unraid_stack_line(array $entry, array $row, string $basePath): string
{
    if ($entry['drift'] === 'gone') {
        return compose2unraid_badge('red', 'times-circle', 'no files');
    }
    if ($entry['drift'] === 'broken') {
        $label = compose2unraid_problem($entry, $basePath)[0];

        return compose2unraid_badge('red', 'times-circle', $label);
    }
    if ($row['container'] === null) {
        return compose2unraid_badge('grey', 'circle-o', 'not deployed');
    }
    if ($row['defined'] === false) {
        return compose2unraid_badge('orange', 'bolt', 'removed on disk');
    }
    if ($entry['drift'] === 'unknown') {
        return compose2unraid_not_checked();
    }
    if ($entry['drift'] === 'changed') {
        return compose2unraid_badge('orange', 'bolt', 'changed');
    }

    return compose2unraid_badge('green', 'check', 'up to date');
}

function compose2unraid_rows(array $entry, array $containers): array
{
    $byService = [];
    foreach ($containers as $container) {
        $byService[$container['service']][] = $container;
    }
    $rows = [];
    foreach ($entry['defined'] ?? [] as $defined) {
        foreach ($byService[$defined['service']] ?? [null] as $container) {
            $rows[] = $container === null
                ? [
                    'container' => null,
                    'defined' => true,
                    'service' => $defined['service'],
                    'name' => $defined['name'],
                    'id' => 'c2u-' . $entry['name'] . '-' . $defined['service'],
                    'labels' => ['net.unraid.docker.icon' => $defined['icon']],
                ]
                : compose2unraid_container_row($container, true);
        }
        unset($byService[$defined['service']]);
    }
    foreach ($byService as $orphans) {
        foreach ($orphans as $container) {
            $rows[] = compose2unraid_container_row($container, false);
        }
    }

    return $rows;
}

function compose2unraid_container_row(array $container, bool $defined): array
{
    return [
        'container' => $container,
        'defined' => $defined,
        'service' => $container['service'],
        'name' => ltrim($container['name'], '/'),
        'id' => 'c2u-' . substr($container['id'], 0, 12),
        'labels' => $container['labels'],
    ];
}

function compose2unraid_health_line(string $state, string $health): string
{
    if ($state === 'paused') {
        return compose2unraid_badge('orange', 'pause-circle', 'paused');
    }
    if ($state !== 'running') {
        return compose2unraid_badge('red', 'times-circle', 'stopped');
    }

    return match ($health) {
        'healthy' => compose2unraid_badge('green', 'check-circle', 'healthy'),
        'unhealthy' => compose2unraid_badge('red', 'times-circle', 'unhealthy'),
        'starting' => compose2unraid_badge('orange', 'clock-o', 'starting'),
        default => compose2unraid_badge('grey', 'minus-circle', 'n/a'),
    };
}

function compose2unraid_icon(string $name, array $labels): string
{
    if (is_file(COMPOSE2UNRAID_ICON_DIR . '/' . $name . '-icon.png')) {
        $url = COMPOSE2UNRAID_ICON_URL . '/' . rawurlencode($name) . '-icon.png';
    } elseif (preg_match('#^https?://#', $labels['net.unraid.docker.icon'] ?? '') === 1) {
        $url = $labels['net.unraid.docker.icon'];
    } else {
        $url = COMPOSE2UNRAID_QUESTION_ICON;
    }

    return '<img src="' . compose2unraid_h($url) . '" class="img"'
        . ' onerror="this.src=\'' . COMPOSE2UNRAID_QUESTION_ICON . '\'">';
}

function compose2unraid_stats_by_id(array $stats, int $cpus): array
{
    $byId = [];
    foreach ($stats as $entry) {
        [$used, $limit] = explode(' / ', $entry['MemUsage'] ?? '0B / 0B', 2) + [1 => '0B'];
        $byId[$entry['ID']] = [
            'cpu' => (float) rtrim($entry['CPUPerc'] ?? '0', '%') / max(1, $cpus),
            'memory' => compose2unraid_bytes($used),
            'limit' => compose2unraid_bytes($limit),
            'memory_percent' => (float) rtrim($entry['MemPerc'] ?? '0', '%'),
        ];
    }

    return $byId;
}

function compose2unraid_bytes(string $size): float
{
    $units = [
        'B' => 1, 'KIB' => 1024, 'MIB' => 1024 ** 2, 'GIB' => 1024 ** 3, 'TIB' => 1024 ** 4,
        'KB' => 1000, 'MB' => 1000 ** 2, 'GB' => 1000 ** 3,
    ];
    if (preg_match('/^([0-9.]+)\s*([A-Za-z]+)$/', trim($size), $parts) !== 1) {
        return 0.0;
    }

    return (float) $parts[1] * ($units[strtoupper($parts[2])] ?? 1);
}

function compose2unraid_format_bytes(float $bytes): string
{
    foreach (['GiB' => 1024 ** 3, 'MiB' => 1024 ** 2, 'KiB' => 1024] as $unit => $size) {
        if ($bytes >= $size) {
            $decimals = $unit === 'GiB' ? 2 : 1;

            return number_format($bytes / $size, $decimals) . ' ' . $unit;
        }
    }

    return number_format($bytes) . ' B';
}

function compose2unraid_container_usage(array $container, array $stats): ?array
{
    $entry = $stats[substr($container['id'], 0, 12)] ?? null;
    if ($entry === null) {
        return null;
    }

    return [
        'cpu' => number_format($entry['cpu'], 2) . '%',
        'cpu_percent' => min(100.0, $entry['cpu']),
        'memory' => compose2unraid_format_bytes($entry['memory']) . ' / '
            . compose2unraid_format_bytes($entry['limit']),
        'memory_percent' => min(100.0, $entry['memory_percent']),
    ];
}

function compose2unraid_meter(string $text, float $percent): string
{
    return '<span class="c2u-usage">' . compose2unraid_h($text)
        . '<span class="c2u-bar"><span style="width: ' . number_format($percent, 1, '.', '')
        . '%"></span></span></span>';
}

function compose2unraid_container_version(string $image): string
{
    if (preg_match('/@sha256:([0-9a-f]{10})/', $image, $digest) === 1) {
        return '<code>@' . $digest[1] . '</code>';
    }
    $colon = strrpos($image, ':');
    $hasTag = $colon !== false && !str_contains(substr($image, $colon), '/');

    return compose2unraid_h($hasTag ? substr($image, $colon + 1) : 'latest');
}

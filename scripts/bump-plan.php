<?php

declare(strict_types=1);

[, $tag, $constraint, $open] = $argv + [null, '', '', ''];

if (preg_match('/^v(\d+\.\d+\.\d+)$/', (string) $tag, $match) !== 1) {
    fwrite(STDERR, "The tag {$tag} is not vX.Y.Z.\n");
    exit(1);
}
$version = $match[1];

if (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', trim((string) $constraint), $match) !== 1) {
    fwrite(STDERR, "The constraint {$constraint} has no ^X.Y or ^X.Y.Z floor.\n");
    exit(1);
}
$floor = $match[1].'.'.$match[2].'.'.($match[3] ?? '0');

$openBumps = [];
foreach (preg_split('/\R/', (string) $open, -1, PREG_SPLIT_NO_EMPTY) as $line) {
    $branch = (string) preg_split('/\s+/', trim($line), 2)[0];
    if (preg_match('/^bump\/core-v(\d+\.\d+\.\d+)$/', $branch, $match) === 1) {
        $openBumps[$branch] = $match[1];
    }
}

$newerOpen = array_filter($openBumps, static fn (string $bump): bool => version_compare($bump, $version, '>'));
$skip = version_compare($floor, $version, '>=') || $newerOpen !== [];
$close = $skip ? [] : array_filter($openBumps, static fn (string $bump): bool => version_compare($bump, $version, '<'));

$segments = static fn (string $v): array => array_map('intval', explode('.', $v));
[$major, $minor] = $segments($version);
[$floorMajor, $floorMinor] = $segments($floor);
$breaking = $major > $floorMajor;

echo 'skip='.($skip ? 'true' : 'false')."\n";
echo "constraint=^{$version}\n";
echo 'commit_type='.($breaking ? 'feat(deps)!' : ($minor !== $floorMinor ? 'feat(deps)' : 'fix(deps)'))."\n";
echo 'auto_merge='.($breaking ? 'false' : 'true')."\n";
echo "branch=bump/core-{$tag}\n";
echo 'close='.implode(' ', array_keys($close))."\n";

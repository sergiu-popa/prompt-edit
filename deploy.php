<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/laravel.php';

set('application', 'prompt.sergiupopa.dev');
set('repository', 'git@github.com:sergiu-popa/prompt-edit.git');
set('branch', 'main');
set('keep_releases', 5);

set('bin/php', '/usr/bin/php8.4');
set('bin/composer', '/usr/bin/php8.4 /usr/local/bin/composer');

// `.env` and `storage/` (logs included) are shared across releases by the Laravel recipe.

// The Hestia PHP-FPM pool for prompt.sergiupopa.dev runs as the deploy user (admin),
// so storage/ and bootstrap/cache are writable without ACL tricks.
set('writable_dirs', []);

// The web domain uses Hestia's `deployer` Apache template (DocumentRoot is current/public) and the
// Symfony-PHP-8_4 backend, whose open_basedir covers the whole domain folder, releases included.
// The queue worker is the systemd unit prompt-edit-worker (WorkingDirectory current/), the
// scheduler is a Hestia cron job for admin, and PostgreSQL 17 listens on localhost only
// (README, "Production").
host('164.90.236.77')
    ->setRemoteUser('admin')
    ->setIdentityFile('~/.ssh/id_rsa')
    ->setPort(22)
    ->set('deploy_path', '/home/admin/web/prompt.sergiupopa.dev')
    ->set('php_fpm_service', 'php8.4-fpm');

// Nothing reaches the server unless CI passed on the exact commit being deployed. The gate runs
// locally with an authenticated `gh`, resolves the branch's head on origin and pins the deploy
// to that commit, so a push that lands mid-deploy can't slip in untested.
task('deploy:ci-gate', function () {
    $target = get('target');
    $repository = get('repository');
    // owner/name for gh, derived from the git URL so the two can't drift apart.
    $githubRepo = preg_replace('~^git@github\.com:|\.git$~', '', $repository);

    if (! testLocally('command -v gh >/dev/null')) {
        throw error('The CI gate needs the GitHub CLI: install gh and run `gh auth login`, then deploy again.');
    }

    $ref = "refs/heads/$target";
    $listing = runLocally('git ls-remote ' . quote($repository) . ' ' . quote($ref));

    // ls-remote matches the pattern against the tail of every ref, so `refs/heads/main` also
    // lists a branch named `x/refs/heads/main`. Only the exact ref counts.
    $sha = null;

    foreach (explode("\n", trim($listing)) as $line) {
        [$candidate, $name] = array_pad(explode("\t", $line, 2), 2, '');

        if ($name === $ref) {
            $sha = $candidate;
        }
    }

    if ($sha === null) {
        throw error("Refusing to deploy: '$target' is not a branch on origin. Only a pushed branch with green CI can be deployed.");
    }

    $runs = json_decode(runLocally(
        'gh run list --repo ' . quote($githubRepo) . ' --workflow ci.yml --commit ' . quote($sha) . ' --limit 1 --json status,conclusion,url',
    ), true);

    if (! is_array($runs) || $runs === []) {
        throw error("Refusing to deploy $target at $sha: CI has not run on this commit yet.");
    }

    ['status' => $status, 'conclusion' => $conclusion, 'url' => $url] = $runs[0];

    if ($status !== 'completed') {
        throw error("Refusing to deploy $target at $sha: CI is still $status ($url).");
    }

    if ($conclusion !== 'success') {
        throw error("Refusing to deploy $target at $sha: CI concluded $conclusion ($url).");
    }

    info("CI passed on $target at $sha ($url).");

    set('target', $sha);
})->once()->desc('Refuses to deploy unless CI succeeded on the branch head being deployed');

before('deploy', 'deploy:ci-gate');

task('deploy:npm:install', function () {
    run('source $HOME/.nvm/nvm.sh && cd {{release_path}} && npm ci --no-audit --no-fund');
});

task('deploy:npm:build', function () {
    run('source $HOME/.nvm/nvm.sh && cd {{release_path}} && npm run build && rm -rf node_modules');
});

after('deploy:vendors', 'deploy:npm:install');
after('deploy:npm:install', 'deploy:npm:build');

// After the symlink flip, PHP-FPM keeps serving the *previous* release: OPcache
// resolves `current/public/index.php` once and then holds an unrevalidated alias
// to the old release's real path, so every subsequent include comes from there
// too. A graceful reload (SIGUSR2) drops the mapping and drains in-flight requests.
//
// Requires the scoped passwordless sudo rule already on the droplet:
//   admin ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
task('deploy:php-fpm:reload', function () {
    $service = get('php_fpm_service');

    // Shell-guard the reload: if the sudoers rule ever goes missing, degrade to
    // a warning instead of failing a deploy whose release is already live.
    $result = run("if sudo -n /usr/bin/systemctl reload $service 2>/dev/null; then echo reloaded; else echo skipped; fi");

    if (trim($result) === 'reloaded') {
        info("PHP-FPM ($service) reloaded - OPcache now serving the new release.");
    } else {
        warning("PHP-FPM reload skipped - restore: admin ALL=(root) NOPASSWD: /usr/bin/systemctl reload $service");
    }
})->desc('Gracefully reload PHP-FPM so OPcache picks up the new release');

// A rollback flips `current` too, so it has the same OPcache problem.
after('deploy:symlink', 'deploy:php-fpm:reload');
after('rollback', 'deploy:php-fpm:reload');

// The recipe's `deploy` ends with `artisan:reload` (Laravel >= 12.41), which runs `queue:restart`
// and `schedule:interrupt` after the flip. The worker exits gracefully and systemd's
// Restart=always starts it again from the new `current`. A rollback needs the same restart.
after('rollback', 'artisan:queue:restart');

after('deploy:failed', 'deploy:unlock');

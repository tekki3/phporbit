<?php

declare(strict_types=1);

return [
    'slug' => 'deployment',
    'title' => 'Deployment',
    'summary' => 'Configuration for FrankenPHP, nginx+FPM and Apache, plus the checklist before you go live.',
    'body' => <<<'HTML'
<p>The same application runs on all three. Nothing in <code>app/</code> or <code>src/</code> changes — only the server configuration and which adapter <code>public/index.php</code> selects, which it does by itself.</p>

<h2>Before you go live</h2>

<ul>
<li><code>APP_DEBUG=false</code>. Debug mode puts stack traces, file paths and SQL into responses.</li>
<li><code>APP_URL</code> set to the real origin, so generated absolute URLs and link previews are right.</li>
<li>Configuration supplied by the environment, not a <code>.env</code> on the server.</li>
<li><code>./orbit migrate</code> run as a deploy step — never at boot.</li>
<li>Document root pointed at <code>public/</code>, so <code>.env</code>, <code>storage/</code> and <code>vendor/</code> are unreachable.</li>
<li><code>storage/</code> writable by the web server user; <code>chmod 750</code> is usually right.</li>
<li>HTTPS terminated, and <code>TRUSTED_PROXIES</code> set if you are behind a load balancer.</li>
<li><code>composer install --no-dev --optimize-autoloader</code>.</li>
</ul>

<h2>FrankenPHP (worker mode)</h2>

<p>The fastest option, and the one that shares its process model with <code>./orbit serve</code>.</p>

[[text]]
# Caddyfile
example.test {
    root * /srv/app/public
    encode zstd gzip

    php_server {
        worker /srv/app/public/index.php
    }
}
[[/text]]

[[bash]]
$ frankenphp run --config /etc/caddy/Caddyfile
[[/bash]]

<p><code>public/index.php</code> detects FrankenPHP and uses the worker adapter, which boots the application once and then serves requests in a loop, collecting cycles between them.</p>

<div class="warn">
<b>Deploying a worker needs a restart</b>
<p>The application is booted once per worker process, so new code is not picked up until the workers restart. Reload FrankenPHP as part of your deploy, after migrations have run.</p>
</div>

<h2>nginx + PHP-FPM</h2>

[[text]]
server {
    listen 443 ssl http2;
    server_name example.test;
    root /srv/app/public;
    index index.php;

    # Real files first; everything else goes to the front controller.
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
    }

    # Dotfiles are never served. The framework refuses them too — two
    # independent protections, because this line gets lost in a refactor.
    location ~ /\. {
        deny all;
    }

    client_max_body_size 8m;
}
[[/text]]

<p>Static files are served by nginx directly, before PHP is invoked, so <code>ServeStaticFiles</code> never runs here. That is the intended arrangement — it is considerably faster.</p>

<h2>Apache</h2>

<p><code>public/.htaccess</code> ships with the framework:</p>

[[text]]
<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>
[[/text]]

<p>The virtual host needs <code>AllowOverride All</code>, or the rewrite rules are ignored and every URL 404s:</p>

[[text]]
<VirtualHost *:443>
    ServerName example.test
    DocumentRoot /srv/app/public

    <Directory /srv/app/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
[[/text]]

<h2>Configuration in production</h2>

<p>The real environment wins over <code>.env</code>, so inject settings the way your platform prefers:</p>

[[text]]
# systemd unit
[Service]
Environment=APP_DEBUG=false
Environment=APP_URL=https://example.test
Environment=DB_DATABASE=/var/lib/app/app.sqlite
EnvironmentFile=/etc/app/secrets.env
[[/text]]

[[text]]
# docker-compose
services:
  app:
    environment:
      APP_DEBUG: "false"
      APP_URL: "https://example.test"
    secrets:
      - db_password
[[/text]]

<p>A stale <code>.env</code> left on a server cannot override these, which is the point of that rule.</p>

<h2>Behind a load balancer</h2>

[[ini]]
TRUSTED_PROXIES=10.0.0.1,10.0.0.2
[[/ini]]

<p><code>X-Forwarded-Proto</code> is believed only from these addresses. Without the list it is ignored entirely — otherwise anyone could claim their plaintext request was HTTPS and unlock Secure-only cookies.</p>

<h2>A deploy sequence</h2>

[[bash]]
$ git pull
$ composer install --no-dev --optimize-autoloader
$ ./orbit migrate                      # before the new code starts serving
$ systemctl reload frankenphp          # or php8.3-fpm
[[/bash]]

<p>Migrations first, so the new schema exists before any process that expects it starts serving. Run them from exactly one host — the ledger prevents double-application, but two concurrent runs can still race.</p>

<h2>Housekeeping</h2>

[[bash]]
# Expired sessions and stale login attempts
0 3 * * *  cd /srv/app && php orbit sessions:gc
[[/bash]]

<p>Neither is required for correctness — expired sessions are refused on read, and old attempts fall outside the throttle window — but both directories otherwise grow forever.</p>

<h2>Which target should you choose?</h2>

<div class="scroller">
<table>
<thead><tr><th>Situation</th><th>Choice</th></tr></thead>
<tbody>
<tr><td>New deployment, containers, want speed</td><td><strong>FrankenPHP.</strong> Boots once, same model as your dev server.</td></tr>
<tr><td>Existing nginx infrastructure</td><td><strong>nginx + FPM.</strong> Boring, well understood, per-request isolation.</td></tr>
<tr><td>Shared or legacy hosting</td><td><strong>Apache.</strong> Works with <code>.htaccess</code> and no root access.</td></tr>
<tr><td>Anything at all</td><td><strong>Not <code>./orbit serve</code>.</strong> It serves connections sequentially.</td></tr>
</tbody>
</table>
</div>

<p>Because the application is identical across all of them, moving later is a configuration change rather than a rewrite — which is the whole point of the constraint the framework is built around.</p>
HTML,
];

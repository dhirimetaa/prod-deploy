# prod-deploy

Stage the files in a local project folder and upload them to **cPanel** or any host with SSH/SFTP. It is not tied to Laravel, Node, or any other framework.

Windows-friendly: credentials live in `deploy/deploy.env`, and uploads go through PHP (phpseclib), so you do not need an interactive `ssh` password prompt.

## Quick start

From the project you want to ship:

```bash
composer require --dev dhirimetaa/prod-deploy
vendor/bin/prod-deploy init
# edit deploy/deploy.env
vendor/bin/prod-deploy build
vendor/bin/prod-deploy push --dry-run
vendor/bin/prod-deploy push
```

Or, while developing this package:

```bash
composer install
php bin/prod-deploy init
```

`init` writes:

| File | Purpose |
| --- | --- |
| `deploy/deploy.env` | SSH host, user, port, remote path, password or key (gitignored) |
| `deploy/config.php` | Optional prebuild, postbuild, and large-folder bundles |
| `deploy/exclude-build.txt` | What never enters the staging folder |
| `deploy/exclude-push.txt` | What is staged but never uploaded (live `.env`, logs, uploads) |

## What build and push do

`build` copies the current project into `prodfiles/`, skipping the exclude-build rules (`.git`, `node_modules`, `vendor`, secrets, and the `deploy/` folder itself).

Optional hooks in `deploy/config.php`:

```php
return [
    'prebuild' => ['npm', 'run', 'build'],
    'postbuild' => ['composer', 'install', '--no-dev', '--optimize-autoloader'],
    'bundle_dirs' => ['vendor'],
    'verify_after_build' => ['public/index.php'],
];
```

`prebuild` runs in the project. `postbuild` runs inside `prodfiles/` after the copy. If you install dependencies in postbuild, remove that directory from `exclude-build.txt` only when you also copy it yourself; postbuild creates it in staging either way.

`push` uploads files whose contents changed since the last push (`deploy/.push-manifest.json`). It does not delete remote files that disappeared locally.

Directories listed in `bundle_dirs` are left out of `push`. Send them once as a single archive:

```bash
vendor/bin/prod-deploy bundle:zip-push
vendor/bin/prod-deploy remote unzip -o bundle.zip
vendor/bin/prod-deploy remote rm bundle.zip
```

Hotfix one path without a full push:

```bash
vendor/bin/prod-deploy push:target public/app.js
```

Run any command in the remote app folder:

```bash
vendor/bin/prod-deploy remote php -v
```

## cPanel

Typical `deploy/deploy.env`:

```env
PROD_SSH_HOST=server.example.com
PROD_SSH_USER=cpaneluser
PROD_SSH_PORT=22
PROD_REMOTE_PATH=/home/cpaneluser/public_html
PROD_SSH_PASSWORD=your-password
```

Enable SSH access in cPanel, or use `PROD_SSH_KEY` with the path to a private key. If the site’s document root should be `public/`, set `PROD_REMOTE_PATH` to the app folder and point the domain at `.../public` in cPanel.

## Install from GitHub

Until the package is on Packagist:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/dhirimetaa/prod-deploy"
        }
    ],
    "require-dev": {
        "dhirimetaa/prod-deploy": "^1.0"
    }
}
```

## Requirements

- PHP 8.2+
- `ext-zip` (only for `bundle:zip` / `bundle:zip-push`)
- phpseclib 3

## License

MIT

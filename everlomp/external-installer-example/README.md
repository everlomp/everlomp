# Everlomp external installer example

Required files:

- `manifest.json` — package metadata and installer form fields.
- `install.sh` — executable installer. Everlomp runs it as root and passes JSON on stdin.

Optional files such as `terms.md`, archives, templates, and application assets may be included beside them.

## Input contract

`install.sh` receives JSON like:

```json
{
  "schema": 1,
  "package_id": "dummy-example",
  "fields": {
    "site_title": "My Everlomp Example",
    "show_timestamp": true
  }
}
```

The script should exit `0` only after installation succeeds. Everlomp writes the primary-app marker after a successful exit, so the package should not edit `/home/everlomp/primary-app` itself.

When replacing the public web root, preserve `/var/www/html/install.php` until the user runs Everlomp cleanup. The application should replace or remove the bootstrap `/var/www/html/index.php`; before installation that file only redirects to `/install.php`.

Schema 1 external packages are primary applications. Their `entrypoint` must be `install.sh`.

## Package lifecycle

Treat `/home/everlomp/external-installs/<id>/` as installer staging only. The final Everlomp cleanup removes uploaded installer packages and the dispatcher helper, so runtime application files must be deployed somewhere else (for example `/var/www/html`, `/home/<app>`, or another persistent runtime path).

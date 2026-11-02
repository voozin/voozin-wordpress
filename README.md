# Voozin for WordPress

Sends all WordPress email through the [Voozin](https://voozin.com) API with a delivery log, and receives inbound email in wp-admin with threaded replies. The wordpress.org listing text is in `readme.txt`; user documentation is at [voozin.com/docs/wordpress](https://voozin.com/docs/wordpress).

## Layout

- `voozin.php`: plugin header and bootstrap
- `includes/class-voozin-mailer.php`: `pre_wp_mail` override that maps `wp_mail()` to `POST /v1/emails`
- `includes/class-voozin-webhook.php`: `POST /wp-json/voozin/v1/webhook`, Standard Webhooks verification
- `includes/class-voozin-inbox.php`: `voozin_message` post type, threading, replies
- `includes/class-voozin-log.php`: `{prefix}voozin_log` table and daily purge
- `includes/class-voozin-admin.php`: Settings, Email log and Inbox screens
- `uninstall.php`: removes settings, log, messages, and the webhook and routes in Voozin

## Development

Point the plugin at a local API with `define( 'VOOZIN_API_BASE', 'http://localhost:3000' );` in `wp-config.php`.

```sh
sh bin/build-zip.sh   # build/voozin-wordpress.zip
```

## Releasing

1. Bump the version in `voozin.php` (header and `VOOZIN_VERSION`) and `Stable tag` in `readme.txt`; add a changelog entry.
2. Tag `vX.Y.Z` and push it. The GitHub mirror's `release.yml` deploys to wordpress.org SVN (secrets `SVN_USERNAME`, `SVN_PASSWORD`) and attaches the zip to a GitHub release.
3. Copy the zip to the landing site's `public/downloads/voozin-wordpress.zip`.

wordpress.org listing assets (banner, icon, screenshots) go in `.wordpress-org/`.

## License

GPLv2 or later.

# Enable NotificationBell SSE with Apache

This procedure works with any GLPI installation path, such as `/var/www/html/glpi`, `/var/www/glpi`, or `/var/www/company`. The installer **asks for the path** and checks that the selected VirtualHost points to the `public/` directory of that same installation. It does not change plugin version 1.1.0 or the plugin tables.

## 1. Prepare the installation

Install and enable NotificationBell in GLPI. Open a terminal in the plugin's actual directory:

```bash
cd /ACTUAL/GLPI/PATH/plugins/notificationbell
```

The server needs PHP CLI 8.2 or later, Apache with `proxy`, `proxy_http`, `headers`, `setenvif`, and `env`, and systemd. **It does not install or require Node.js.** The script checks these requirements. The VirtualHost must be in a file containing **only one** `<VirtualHost>` block, a GLPI `DocumentRoot`, and a simple `CustomLog`. If the file contains multiple log rules or VirtualHosts, the installer stops to avoid changing the wrong site.

The installer accepts VirtualHost files with Windows line endings (**CRLF**) and preserves that format when adding the SSE configuration. The `install-sse-apache.sh` file in the ZIP uses Unix line endings (**LF**). If a transfer converts it to CRLF and Bash fails before showing the prompts, restore it with `sed -i 's/\r$//' service/install-sse-apache.sh`.

When SELinux is in `Enforcing` mode, Apache needs permission to connect to the local relay. If the installer reports that `httpd_can_network_connect` is disabled, an administrator can enable it with:

```bash
sudo setsebool -P httpd_can_network_connect on
```

## 2. Review the plan

```bash
sudo bash service/install-sse-apache.sh --dry-run
```

Answer the prompts:

1. **Absolute GLPI path:** the directory containing `public/` and `plugins/`. The script suggests a path based on its own location, but you must confirm it.
2. **Origin URL:** the exact address shown in the browser, for example `https://glpi.company.com` or `http://10.20.3.168`. Do not include a path or a trailing `/`.
3. **Apache VirtualHost:** the file serving that site, for example `/etc/httpd/conf.d/glpi.conf` or `/etc/apache2/sites-available/glpi.conf`.
4. **GLPI_CONFIG_DIR and files directory:** accept the suggested values only if GLPI uses `<ROOT>/config` and `<ROOT>/files`. If these locations were overridden, enter their actual paths.
5. **Web user, Apache service, and local port:** confirm or change the suggested values.

The `--dry-run` validation does not create files or restart services. You can also pass values as options, for example:

```bash
sudo bash service/install-sse-apache.sh --dry-run \
  --glpi-root /var/www/company \
  --origin https://glpi.company.com \
  --vhost /etc/httpd/conf.d/company.conf \
  --web-user apache --apache-service httpd
```

See all options with `bash service/install-sse-apache.sh --help`.

## 3. Install

```bash
sudo bash service/install-sse-apache.sh
```

The script asks for the values again. You can also pass the same options as in the previous step without `--dry-run`. It generates a random secret, creates `notificationbell.php` in `GLPI_CONFIG_DIR`, prepares the revisions directory under the GLPI files directory, installs the included PHP relay as `notificationbell.service`, and adds a proxy route to the VirtualHost. The SSE route uses the same origin as GLPI. The connection token is excluded from that VirtualHost's `CustomLog`. Before reloading Apache, the script checks PHP and Apache syntax. It then verifies that an invalid capability receives HTTP 401 through the proxy. If this check fails, it restores the VirtualHost copy and removes the new files. It keeps a `.bak` copy next to the VirtualHost.

The installer refuses to overwrite an existing SSE configuration. If you have already configured the relay manually, keep that configuration and use the verification section.

## 4. Verify

```bash
sudo systemctl status notificationbell --no-pager
sudo journalctl -u notificationbell -n 30 --no-pager
```

Reload a GLPI tab. The bell indicator should show **Live**. From another session, create or modify a ticket visible to that user: the notification should appear without reloading the page. If the relay disconnects, the bell polls every 10 seconds while the tab is visible.

If there are problems, also check `sudo apachectl -t` (or `sudo apache2ctl -t`) and the VirtualHost error log. The relay listens only on `127.0.0.1`; do not expose its port to the network. In installations with multiple PHP servers, the files/revisions directory must be shared by every node that generates events.

## Installer scope

This script configures **Apache with systemd**. For Nginx, the `service/` directory contains the examples `nginx.conf.example`, `relay.env.example`, `notificationbell.config.php.example`, and `notificationbell.service`; replace their example values with those for your installation. A GLPI configuration outside `public/` and the exact origin are required in both cases.

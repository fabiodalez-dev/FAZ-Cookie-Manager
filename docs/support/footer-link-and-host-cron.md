# Support replies — footer consent link and hosting cron

These are drafts for the next release; the changes are local and not yet published.

## Footer link

Thanks for the suggestion! The plugin already has a `[faz_cookie_settings]` shortcode, and I have extended it with a plain link variant for footers:

```text
[faz_cookie_settings type="link" text="Cookie preferences"]
```

Add it to a Shortcode block in your site-wide footer. It uses your theme’s link styling and opens the consent preferences. You can then turn off **Banner → Advanced → Show revisit consent widget**, provided that setting is not locked by jurisdiction routing. If routing locks it on, the widget remains alongside the link. The link needs the consent banner runtime, so it will not work on pages excluded from the banner.

## Hosting cron and disabled pageviews

Thanks for the report! If your host’s existing job runs WordPress’s `wp-cron.php` regularly, it already processes the plugin’s scheduled scans and cleanup jobs. You do not need WP-CLI or a separate Rulesets cron job. `DISABLE_WP_CRON` disables automatic cron spawning from page visits; it does not stop an external hosting cron from running WordPress events.

I cannot see the cron configuration mentioned in the message, so I cannot yet verify its command or frequency. Please paste those two values, or attach the screenshot again. There is no need to share passwords.

If the host needs an example, in **cPanel → Cron Jobs** they can use `*/5` for the minute field and `*` for the remaining fields, with:

```sh
/usr/bin/curl --fail --silent --show-error 'https://YOUR-WORDPRESS-URL/wp-cron.php?doing_wp_cron' > /dev/null
```

Replace the URL with the actual WordPress installation URL, including its subdirectory if applicable. Ask the host to confirm the curl executable path and that authentication/firewall rules allow the request. Keep the existing job if it already does this; do not add a duplicate.

The report lists scans and cleanup scheduled for September 3. Whether those dates were overdue depends on when the report was generated. The useful check is whether overdue timestamps advance after the host’s cron runs; scheduled timestamps alone do not prove successful execution.

You were also right about pageviews: with tracking disabled, `0` was misleading. I have changed the dashboard to show `--` with a disabled-tracking explanation. This does not enable pageview tracking, and consent logging remains independent.

Sources: [WordPress: using a system scheduler](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/), [cPanel Cron Jobs](https://docs.cpanel.net/cpanel/advanced/cron-jobs/).

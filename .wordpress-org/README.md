# `.wordpress-org/` — WordPress.org assets

This directory contains everything needed to publish FAZ Cookie Manager on
[wordpress.org/plugins](https://wordpress.org/plugins/). It is **excluded from
the distribution ZIP** via `.distignore` — these files never ship to end
users, they only exist to be copied into the plugin's SVN `assets/` directory.

## Contents

```
.wordpress-org/
├── PUBLISHING-GUIDE.md        ← full submission + SVN workflow
├── README.md                  ← this file
├── screenshot-1.png           ← ordered screenshots that wp.org expects
├── screenshot-2.png
├── …
├── screenshot-10.png
└── screenshots-src/           ← raw captures from the Playwright script,
                                 named 01-…png so they sort correctly
```

## How to refresh screenshots

```bash
# 1. Deploy current plugin to the local test site.
rsync -a --delete \
  --exclude tests --exclude test-results --exclude node_modules \
  ./ /Users/fabio/Sites/faz-test/wp-content/plugins/faz-cookie-manager/

# 2. Capture (requires http://localhost:9998 to be serving /Users/fabio/Sites/faz-test).
WP_BASE_URL=http://localhost:9998 \
WP_ADMIN_USER=admin WP_ADMIN_PASS=admin \
node scripts/capture-wporg-screenshots.mjs

# 3. Sync the numbered copies that wp.org expects.
cd .wordpress-org/screenshots-src
for f in 01-*.png 02-*.png 03-*.png 04-*.png 05-*.png 06-*.png 07-*.png 08-*.png 09-*.png 10-*.png; do
  n=$(echo "$f" | sed -E 's/^0*([0-9]+).*/\1/')
  cp "$f" "../screenshot-${n}.png"
done
```

## See also

- `PUBLISHING-GUIDE.md` — end-to-end publishing walkthrough
- `../scripts/capture-wporg-screenshots.mjs` — the Playwright capture script
- `../readme.txt` — the file rendered on the plugin directory page
- `../release.md` — the authoritative release flow

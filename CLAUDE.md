# Claude Notes

- After modifying frontend JavaScript assets, always regenerate the minified files before considering the work complete.
- Run `npm run build:min` after changes to `frontend/js/script.js`, `frontend/js/gcm.js`, or `frontend/js/tcf-cmp.js`.
- Commit the corresponding generated files (`frontend/js/script.min.js`, `frontend/js/gcm.min.js`, `frontend/js/tcf-cmp.min.js`) together with the source changes.

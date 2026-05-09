## Summary

<!-- 1-3 bullets describing what this PR changes and why -->

## Changes

- [ ] Code
- [ ] Docs
- [ ] Tests / manual verification

## Security checklist

- [ ] No new endpoints without an explicit `permission_callback`
- [ ] No plaintext storage of Application Passwords
- [ ] No new arbitrary code execution / SQL / file-write paths
- [ ] All forms use nonces (`wp_nonce_field` + `check_admin_referer`)
- [ ] All inputs sanitized; all outputs escaped
- [ ] No third-party network calls added without disclosure

## Manual test plan

- [ ] Plugin activates with no fatal errors
- [ ] Tools → AI Site Connector loads
- [ ] Setup wizard creates the AI user
- [ ] Application Password generation works and shows the password exactly once
- [ ] Revoke removes the password
- [ ] Audit log records the events
- [ ] curl to `/wp-json/wp/v2/users/me` succeeds with the new credentials

## Screenshots / output

<!-- Optional -->

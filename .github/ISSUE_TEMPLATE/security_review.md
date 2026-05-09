---
name: Security review request
about: Request a focused security review of a change or area
title: "[security] "
labels: security, needs-manual-test
assignees: ''
---

> **Do NOT use this template to report a real vulnerability.** See [SECURITY.md](../../SECURITY.md) — report privately via Security Advisory or email.

**Area to review**

<!-- e.g. "REST controller permission_callbacks", "audit log table integrity", "credential-mint flow" -->

**What changed**

<!-- Link to PR or commit. Briefly describe the change. -->

**Specific questions / concerns**

<!-- e.g. "Does this still enforce manage_options?", "Is the new filter callable by an unauthenticated request?" -->

**Threat model assumptions affected**

- [ ] Trust boundary changes
- [ ] New REST routes
- [ ] New admin actions
- [ ] New cron / scheduled tasks
- [ ] New outbound network calls
- [ ] New file writes

**Test evidence**

<!-- PHP lint output, grep results, screenshots of the admin UI, curl transcripts. Redact real credentials. -->

**Reviewer checklist**

- [ ] Every admin action: `current_user_can` + `check_admin_referer`
- [ ] Every REST route: explicit `permission_callback`
- [ ] Inputs sanitized; outputs escaped
- [ ] No new use of forbidden PHP functions
- [ ] No new plaintext credential storage
- [ ] No new outbound network calls without disclosure
- [ ] Audit log captures the new event(s)

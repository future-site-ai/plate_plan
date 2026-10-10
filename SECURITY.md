# Security Policy

Thank you for helping keep Plate Plan and its users safe. 🔒

## Important: Plate Plan is a personal, local app

Plate Plan is designed to run **on your own computer or on a trusted home network** for one person or one household. It has **no user accounts and no login**. The web app, the REST API (`api.php`) and the MCP server (`mcp.php`) are open to anyone who can reach them.

**Do not expose Plate Plan directly to the internet.** If you need remote access, put it behind a VPN, or a reverse proxy with authentication (for example HTTP basic auth over HTTPS).

Because of this design, "there is no authentication" is a known limitation, not a vulnerability.

## Supported versions

Plate Plan is maintained by a single person, so security fixes are made only for the **latest version on the `main` branch**.

| Version | Supported |
|---|---|
| Latest `main` | ✅ |
| Older commits and forks | ❌ |

## Reporting a vulnerability

**Please do not open a public issue, discussion or pull request for security problems.**

Report privately in one of these ways:

1. **Preferred:** GitHub's [private vulnerability reporting](https://github.com/future-site-ai/plate_plan/security/advisories/new) (the **Security** tab → **Report a vulnerability**).
2. Email **contact@futuresite.it** with the subject `[SECURITY] Plate Plan`.

Please include a description of the problem and its impact, steps to reproduce, the affected commit, and any ideas for a fix.

## What to expect

Plate Plan is maintained in spare time by one person, so please be patient:

- **Acknowledgement:** within **7 days**.
- **First assessment:** within **14 days**.
- **Fix:** as soon as reasonably possible, depending on severity.

Once a fix is released I will publish a security advisory. **You will be credited** in it, unless you prefer to stay anonymous. Please give a reasonable time to fix the issue before any public disclosure.

## Scope

**In scope:**

- Leaking the AI API key from `.env.local` (through pages, API or MCP responses, logs or errors)
- Cross-site scripting (XSS) through meal names, ingredients or AI answers
- SQL injection or reading files outside the app
- A malicious website being able to read or change your meal plan through your browser (for example through the CORS settings)
- Prompt injection that makes the AI assistant do something outside the meal-planning tools

**Out of scope:**

- The lack of authentication when Plate Plan is exposed to the internet or an untrusted network (see above)
- Vulnerabilities in third-party dependencies with no demonstrated impact on Plate Plan (please report them upstream)
- Behaviour of external AI providers, and wrong nutrition advice (AI can make mistakes; always ask a professional for medical dietary advice)
- Denial of service, automated scanner output without a proof of concept, and missing security headers alone

## Running Plate Plan safely

- Keep it on `localhost` or a trusted network.
- Never commit `.env.local` (it holds your AI key).
- Keep it updated to the latest `main`.

Thank you for reporting responsibly! 💚

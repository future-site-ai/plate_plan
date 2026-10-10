# Contributing to Plate Plan

Thank you for your interest in Plate Plan! 🥗 Contributions of every size are welcome: bug reports, ideas, documentation, recipes and code.

You do not need to ask for permission to help. If something here is unclear, open an issue and we will improve this guide.

## Ways to help

- 🐛 **Report a bug**: open an [issue](https://github.com/future-site-ai/plate_plan/issues) with the steps to reproduce it, what you expected, and what happened instead. Mention which AI provider you use (`AI_PROVIDER`).
- 💡 **Suggest a feature**: open an issue that describes the problem you want to solve, not only the solution.
- 📝 **Improve the docs**: fix typos, clarify the README, or document setup with a new AI provider.
- 🍳 **Add recipes**: extend the seed recipes in `src/fixture.php` (use explicit units such as `200g`, `1 pc`, `15 ml`).
- 🧑‍💻 **Write code**: pick an issue labelled `good first issue` or `help wanted`.

## Before you start coding

1. **Look for an existing issue** first. If there is none, open one so we can agree on the approach before you spend time on it. Small fixes (typos, obvious bugs) can go straight to a pull request.
2. **Comment on the issue** to say you are working on it, so two people do not do the same work.

## Development setup

```bash
git clone https://github.com/<your-username>/plate_plan.git && cd plate_plan
cp .env.example .env.local     # set AI_PROVIDER and AI_API_KEY, or leave them empty
docker compose up -d
```

Open **http://localhost:1111**. Without an API key the app falls back to local heuristic planning, so you can work on most features without paying for an AI provider. A local [Ollama](https://ollama.com) model is a free option for testing the AI features (see the README).

Add PHP dependencies with `docker compose exec app composer require <vendor/package>`.

## Where things live

| Path | What it does |
|---|---|
| `public/index.php`, `public/js`, `public/css` | The web UI (planner board, chat, grocery list) |
| `public/api.php` | REST / JSON API |
| `public/mcp.php`, `src/MCP/McpServer.php` | MCP server (JSON-RPC 2.0) |
| `src/AI/` | AI provider selection (`ProviderFactory`) and assistant tools |
| `src/Services/` | Business logic and the centralized prompt service |
| `src/db.php`, `src/fixture.php` | SQLite schema and seed recipes |
| `src/request_guard.php` | Blocks requests from other websites. Every entry point in `public/` must call `plate_plan_guard_request()` |

## Project rules

- **Keep it provider-agnostic.** New AI features must work with every provider supported by `ProviderFactory`, not only one.
- **Keep the offline fallback working.** The app must still be usable with no AI provider configured.
- **Keep explicit units** for ingredients, so the grocery list can add up matching amounts.
- **Never commit secrets or data.** `.env.local` and `data/*.sqlite` are not tracked; keep it that way.

## Code style

- PHP 8.2, [PSR-12](https://www.php-fig.org/psr/psr-12/), 4-space indentation.
- Keep pull requests focused: one fix or feature per PR.

## Checks before you open a pull request

Plate Plan does not have an automated test suite yet (adding one is a great contribution!). Until then, please check by hand:

- The app starts with `docker compose up -d` and the planner loads.
- The feature you changed works, **with and without** an AI provider configured.
- There are no PHP errors in `docker compose logs app` or JavaScript errors in the browser console.

## Pull request process

1. **Fork** the repository and create a branch from `main`, for example `fix/grocery-units` or `feat/mistral-provider`.
2. Make your changes and commit them with clear messages, for example `Fix grocery totals for tbsp units`.
3. **Push** your branch and open a **pull request** against `main`.
4. In the description, explain **what** you changed and **why**, link the issue (`Closes #123`), and add screenshots for UI changes.
5. A maintainer will review your PR, may ask for changes, and will merge it when it is ready.

Please be patient: this project is maintained in our spare time, and we try to reply within a week.

## Reporting security issues

**Do not open a public issue for security vulnerabilities.** Use GitHub's [private vulnerability reporting](https://github.com/future-site-ai/plate_plan/security/advisories/new) or email **contact@futuresite.it**.

## Code of conduct

Be kind and respectful. We want everyone to feel welcome, whatever their experience level. Harassment or abusive behaviour is not tolerated, and maintainers may remove comments or block users who break this rule.

## License

By contributing, you agree that your contributions are licensed under the project's [MIT License](LICENSE).

Thank you for helping make Plate Plan better! ⭐

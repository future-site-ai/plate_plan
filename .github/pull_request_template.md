<!--
Thank you for contributing to Plate Plan! 🥗
Please fill in this template. Small, focused pull requests are reviewed faster.
-->

## What does this PR do?

<!-- A short description of the change. -->

## Why?

<!-- The problem it solves or the feature it adds. -->

Closes #<!-- issue number -->

## Type of change

- [ ] 🐛 Bug fix
- [ ] ✨ New feature
- [ ] 🤖 AI provider or assistant
- [ ] 🍳 Recipes / seed data
- [ ] 📝 Documentation
- [ ] ♻️ Refactoring (no change in behaviour)

## How did you test it?

<!-- Plate Plan has no automated tests yet. Please describe what you checked by hand. -->

- [ ] The app starts with `docker compose up -d` and the planner loads.
- [ ] It works **with** an AI provider configured (which one: <!-- e.g. Gemini, Ollama -->).
- [ ] It works **without** an AI provider (offline fallback).
- [ ] No PHP errors in `docker compose logs app` and no errors in the browser console.

## Screenshots

<!-- For UI changes: before and after. Delete this section if not relevant. -->

## Checklist

- [ ] I read [CONTRIBUTING.md](https://github.com/future-site-ai/plate_plan/blob/main/CONTRIBUTING.md).
- [ ] New AI features work with every provider in `ProviderFactory`, not only one.
- [ ] Ingredients use explicit units (`200g`, `1 pc`, `15 ml`).
- [ ] I updated the README if setup or behaviour changed.
- [ ] I did not commit secrets, `.env.local` or `data/*.sqlite`.

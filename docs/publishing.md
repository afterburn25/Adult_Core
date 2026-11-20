# Publish the current Adult_Core development milestone to GitHub

Repository: https://github.com/afterburn25/Adult_Core

The provided ZIP is a local project snapshot. **It has not been published to GitHub automatically.** To push these files, extract the ZIP, then from its `Adult_Core/` directory run:

```bash
git init
git branch -M main
git add .
git commit -m "Build M4 signed module and theme package management"
git remote add origin https://github.com/afterburn25/Adult_Core.git
git push -u origin main
```

These commands assume the remote has no existing commits. If commits already exist, clone the repository and merge the files instead; do not force-push. GitHub authentication and repository write access are required.

After pushing, verify that `AGENTS.md`, `docs/SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/SECURITY.md`, `docs/CHANGELOG.md`, `docs/DECISIONS.md` and `docs/INSTALLATION_LIFECYCLE.md` appear in the repo. Future sessions should read `AGENTS.md` first.

Never commit `.env`, `config/pro-agent-secret.php`, `storage/install-state.json` or runtime logs.

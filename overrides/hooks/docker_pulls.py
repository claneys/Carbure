"""MkDocs hook: reads the Docker Hub pull count of the image at build time.

The header shows it next to the GitHub link (overrides/partials/source.html). It is read
here rather than in the browser so that visitors never call a third party (the Docker Hub
API sends no CORS header anyway). A failure (offline build, Docker Hub down) leaves the
header without a count, logged at info level so that `mkdocs build --strict` still passes;
the weekly build of the Docs workflow keeps the count fresh.
"""

import functools
import json
import logging
import urllib.request

log = logging.getLogger("mkdocs.hooks.docker_pulls")


def _short(count: int) -> str:
    """Formats a count the way Material shows the GitHub stars: 950, 3.8k, 1.2M."""
    for limit, suffix in ((1_000_000, "M"), (1_000, "k")):
        if count >= limit:
            return f"{count / limit:.1f}".rstrip("0").rstrip(".") + suffix
    return str(count)


@functools.lru_cache(maxsize=None)
def _pulls(image: str):
    """One Docker Hub call per build, shared by the French and English sites."""
    try:
        with urllib.request.urlopen(f"https://hub.docker.com/v2/repositories/{image}/", timeout=10) as response:
            count = json.load(response).get("pull_count")
    except (OSError, ValueError) as error:
        log.info("Docker Hub pull count unavailable: %s", error)
        return None
    return _short(count) if isinstance(count, int) else None


def on_page_context(context, page, config, nav, **kwargs):
    """Exposes `docker_pulls` to the templates of every page (None when unavailable).

    Per page rather than in config.extra: the i18n plugin replaces `extra` for the English
    site.
    """
    image = config.extra.get("docker_image")
    context["docker_pulls"] = _pulls(image) if image else None
    return context

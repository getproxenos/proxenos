# =============================================================================
# docker-bake.hcl — bug-free-happiness image build configuration
# =============================================================================
# Two variants from one Dockerfile:
#   app    <- serversideup/php:${PHP_VERSION}-frankenphp   (HTTP service)
#   worker <- serversideup/php:${PHP_VERSION}-cli          (CLI worker services)
#
# Published prod images go to GHCR; dev images are local-only (built by `make dev`).
# Layer caching in CI uses GitHub Actions cache (type=gha) — see .github/workflows.
#
# Replace OWNER with the GitHub org/user. CI overrides REGISTRY/TAG from the
# workflow (derived from github.repository / the git ref).
#
# Usage:
#   make dev                          # build local dev images
#   docker buildx bake prod           # build prod images (no push)
#   docker buildx bake app-prod --push

# GitHub owner / GHCR namespace (lowercase — Docker tag rule). Change this if you
# publish under a different org. CI overrides REGISTRY/TAG from the workflow
# (derived from github.repository).
variable "REGISTRY" {
  default = "ghcr.io/simensen/bug-free-happiness"
}

variable "PHP_VERSION" {
  default = "8.5"
}

variable "TAG" {
  default = "dev"
}

function "base_image" {
  params = [variant]
  result = "serversideup/php:${PHP_VERSION}-${variant}"
}

target "_common" {
  context    = "."
  dockerfile = "docker/php/Dockerfile"
  labels = {
    "org.opencontainers.image.source" = "https://github.com/simensen/bug-free-happiness"
  }
}

# ---- prod (published to GHCR) ----
target "app-prod" {
  inherits = ["_common"]
  target   = "prod"
  args     = { BASE_IMAGE = base_image("frankenphp") }
  tags     = ["${REGISTRY}/app:${TAG}"]
}

target "worker-prod" {
  inherits = ["_common"]
  target   = "prod"
  args     = { BASE_IMAGE = base_image("cli") }
  tags     = ["${REGISTRY}/worker:${TAG}"]
}

# ---- dev (local only, never pushed) ----
target "app-dev" {
  inherits = ["_common"]
  target   = "dev"
  args     = { BASE_IMAGE = base_image("frankenphp") }
  tags     = ["bug-free-happiness/app:dev"]
}

target "worker-dev" {
  inherits = ["_common"]
  target   = "dev"
  args     = { BASE_IMAGE = base_image("cli") }
  tags     = ["bug-free-happiness/worker:dev"]
}

group "prod" {
  targets = ["app-prod", "worker-prod"]
}

group "dev" {
  targets = ["app-dev", "worker-dev"]
}

group "default" {
  targets = ["dev"]
}

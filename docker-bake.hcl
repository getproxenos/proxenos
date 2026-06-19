# =============================================================================
# docker-bake.hcl — Proxenos image build configuration
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
#
# USER_ID / GROUP_ID:
#   Default to null. When null Bake omits the arg and the Dockerfile leaves
#   www-data's native UID/GID. `make dev` captures the host's UID/GID via
#   $(shell id -u/-g) and passes them through so bind-mounted files are
#   owned correctly.
#
#   Do NOT hardcode values here: HCL values override Compose values for the
#   same key, so a hardcoded default cannot be overridden from Compose.

# GitHub owner / GHCR namespace (lowercase — Docker tag rule). Change this if you
# publish under a different org. CI overrides REGISTRY/TAG from the workflow
# (derived from github.repository).
variable "REGISTRY" {
  default = "ghcr.io/getproxenos/proxenos"
}

variable "PHP_VERSION" {
  default = "8.5"
}

variable "TAG" {
  default = "dev"
}

variable "USER_ID" {
  default     = null
  description = "Host UID for dev container permission fix. Set via make dev."
}

variable "GROUP_ID" {
  default     = null
  description = "Host GID for dev container permission fix. Set via make dev."
}


function "base_image" {
  params = [variant]
  result = "serversideup/php:${PHP_VERSION}-${variant}"
}

target "_common" {
  context    = "."
  dockerfile = "docker/php/Dockerfile"
  labels = {
    "org.opencontainers.image.source" = "https://github.com/getproxenos/proxenos"
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
  args     = {
    BASE_IMAGE = base_image("frankenphp")
    USER_ID    = USER_ID
    GROUP_ID   = GROUP_ID
  }
  tags     = ["proxenos/app:dev"]
}

target "worker-dev" {
  inherits = ["_common"]
  target   = "dev"
  args     = {
    BASE_IMAGE = base_image("cli")
    USER_ID    = USER_ID
    GROUP_ID   = GROUP_ID
  }
  tags     = ["proxenos/worker:dev"]
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

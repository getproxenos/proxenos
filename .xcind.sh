# shellcheck shell=bash
# shellcheck disable=SC2034

_SYMFONY_APP_ENV="${APP_ENV:-dev}"

XCIND_ADDITIONAL_CONFIG_FILES=(".xcind.${_SYMFONY_APP_ENV}.sh")

XCIND_COMPOSE_FILES=("compose.common.yaml" "compose.${_SYMFONY_APP_ENV}.yaml")

XCIND_COMPOSE_ENV_FILES=(".env.docker" ".env.docker.${_SYMFONY_APP_ENV}")

XCIND_PROXY_EXPORTS=(
    "app:8080"
    "db:5432;type=assigned"
)

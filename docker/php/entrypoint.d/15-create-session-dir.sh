#!/bin/sh
# Create the PHP session save_path directory at container start, after the
# dev bind-mount is in place. PHP's native files handler (framework.session
# handler_id: null) never creates session.save_path itself, and the dir lives
# under the gitignored, bind-mounted var/ tree — so nothing else creates it.
# Path mirrors docker/php/conf.d/session.ini's ${APP_ENV}-derived location.
set -e
mkdir -p "/var/www/html/var/sessions/${APP_ENV:-dev}"

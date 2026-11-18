#!/bin/bash
set -e
case "$1" in
  "web")
    echo "Starting web application"
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/apache.conf
    ;;
  "worker")
    echo "Starting queue worker"
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/worker.conf
    ;;
  "scheduler")
    echo "Starting scheduler"
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/scheduler.conf
    ;;
  *)
    echo "Starting web application"
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/apache.conf
    ;;
esac

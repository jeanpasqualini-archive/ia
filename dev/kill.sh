#!/usr/bin/env bash
while true;do
    inotifywait -e modify -r $PWD/src
    docker kill ia_app_run_1
    docker rm ia_app_run_1
done
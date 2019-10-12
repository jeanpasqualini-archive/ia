#!/usr/bin/env bash
while true;do
    docker-compose run --rm app php ./console --curse --step-by-step
done
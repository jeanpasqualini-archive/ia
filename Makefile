.DEFAULT_GOAL := help

DC  := docker-compose
RUN := $(DC) run --rm app

.PHONY: help build install run play test shell logs clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

build: ## Build the docker image
	$(DC) build

install: vendor ## Install composer dependencies

vendor: composer.json composer.lock
	$(RUN) composer install --no-interaction --no-progress
	@touch vendor

app/log:
	@mkdir -p app/log

ARGS ?=

run: install app/log ## Play the game in the container (full screen, no sound)
	$(RUN) php ./console $(ARGS)

# The container has no sound card and cannot be given one on macOS, so the
# music only exists on this target. It logs to app/log/dev.log like the
# container does, so `make logs` works either way.
play: install app/log ## Play on the host PHP, with sound (needs php and libsdl2)
	php ./console --log app/log/dev.log $(ARGS)

test: install ## Run the test suite
	$(RUN) ./vendor/bin/phpunit

shell: install ## Open a shell inside the container
	$(RUN) bash

logs: app/log ## Follow the game log
	@touch app/log/dev.log
	@tail -f app/log/dev.log

clean: ## Remove vendor, cache and logs
	rm -rf vendor app/cache/* app/log/*

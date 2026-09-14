IMAGE ?= uptimeify-whmcs-dev
# Allocate a TTY only when stdin is a terminal, so targets also work in non-interactive shells and CI.
TTY   := $(shell [ -t 0 ] && echo -it)
RUN   := docker run --rm $(TTY) -v "$(CURDIR)":/app -w /app -u "$(shell id -u):$(shell id -g)" -e COMPOSER_HOME=/tmp/composer $(IMAGE)

.PHONY: build-image install test stan lint fix build shell ci sandbox-up sandbox-down sandbox-cron sandbox-logs

build-image:
	docker build -t $(IMAGE) -f docker/Dockerfile.dev docker

install: ## composer install inside the container
	$(RUN) composer install --no-interaction --prefer-dist

test:
	$(RUN) vendor/bin/phpunit

stan:
	$(RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

lint:
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

fix:
	$(RUN) vendor/bin/php-cs-fixer fix

build:
	$(RUN) sh bin/build.sh

shell:
	$(RUN) sh

ci: lint stan test

# WHMCS sandbox (docker/sandbox/README.md). Needs docker/whmcs/ and a WHMCS development licence.
SANDBOX := docker compose -f docker/docker-compose.sandbox.yml

sandbox-up:
	$(SANDBOX) up -d --build

sandbox-down:
	$(SANDBOX) down

sandbox-cron: ## run the WHMCS cron once as the FPM user (drives AfterCronJob/DailyCronJob)
	$(SANDBOX) exec -T -u www-data php php -q /var/www/html/crons/cron.php all --force

sandbox-logs:
	$(SANDBOX) logs -f php web

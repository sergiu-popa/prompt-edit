# Plain `docker exec` (no `-t`) so batch targets work without a TTY (CI, IDE agents).
# Interactive shells keep `docker exec -it` (bash, tinker).
up:
	docker compose up -d

down:
	docker compose down

restart: down up

setup: env up composer key migrate npm-build
	@echo "Setup complete. Visit http://prompt-edit.localhost"

env:
	cp .env.example .env

composer:
	docker exec prompt-edit-app composer install

key:
	docker exec prompt-edit-app php artisan key:generate

migrate:
	docker exec prompt-edit-app php artisan migrate

migrate-test:
	docker exec -e DB_HOST=test-postgres -e DB_DATABASE=prompt_edit_test prompt-edit-app php artisan migrate

fresh:
	docker exec prompt-edit-app php artisan migrate:fresh

fresh-test:
	docker exec -e DB_HOST=test-postgres -e DB_DATABASE=prompt_edit_test prompt-edit-app php artisan migrate:fresh

seed:
	docker exec prompt-edit-app php artisan db:seed

refresh: fresh seed

refresh-test: fresh-test

bash:
	docker exec -it prompt-edit-app /bin/bash

tinker:
	docker exec -it prompt-edit-app php artisan tinker

npm:
	docker exec prompt-edit-app npm install

npm-dev:
	docker exec prompt-edit-app bash -c "npm install && npm run dev"

npm-build:
	docker exec prompt-edit-app bash -c "npm install && npm run build"

test: migrate-test
	docker exec prompt-edit-app php artisan test --compact $(if $(filter),--filter="$(filter)")

test-unit:
	docker exec prompt-edit-app php artisan test --compact --testsuite=Unit

test-feature: migrate-test
	docker exec prompt-edit-app php artisan test --compact --testsuite=Feature

lint:
	docker exec prompt-edit-app composer lint

lint-fix:
	docker exec prompt-edit-app composer lint-fix

stan:
	docker exec prompt-edit-app composer stan

js-check:
	docker exec prompt-edit-app npm run lint
	docker exec prompt-edit-app npm run format:check
	docker exec prompt-edit-app npm run types

js-fix:
	docker exec prompt-edit-app npm run lint:fix
	docker exec prompt-edit-app npm run format

js-test:
	docker exec prompt-edit-app npm test

e2e:
	docker exec prompt-edit-app npm run build
	docker compose run --rm playwright npx playwright test $(if $(filter),--grep "$(filter)")

# Opt-in smoke against production, never part of `make e2e` or CI. Needs `make up` (the
# playwright service shares the app container's network). `-e NAME` passes the shell's value,
# so the reviewer password never appears on a command line.
smoke-production:
	@test -n "$$E2E_BASE_URL" || { echo "Set E2E_BASE_URL (e.g. https://prompt.sergiupopa.dev)."; exit 1; }
	@test -n "$$E2E_REVIEWER_EMAIL" || { echo "Set E2E_REVIEWER_EMAIL."; exit 1; }
	@test -n "$$E2E_REVIEWER_PASSWORD" || { echo "Set E2E_REVIEWER_PASSWORD (read -rs E2E_REVIEWER_PASSWORD; export E2E_REVIEWER_PASSWORD)."; exit 1; }
	docker compose run --rm -e E2E_BASE_URL -e E2E_REVIEWER_EMAIL -e E2E_REVIEWER_PASSWORD playwright npx playwright test --config=playwright.production.config.ts

check: lint stan test js-check js-test

cache:
	docker exec prompt-edit-app php artisan optimize:clear

app-logs:
	docker logs --tail 500 prompt-edit-app

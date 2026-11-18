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
	docker exec -e DB_HOST=prompt-edit-test-mysql prompt-edit-app php artisan migrate

fresh:
	docker exec prompt-edit-app php artisan migrate:fresh

fresh-test:
	docker exec -e DB_HOST=prompt-edit-test-mysql prompt-edit-app php artisan migrate:fresh

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

check: lint stan test

cache:
	docker exec prompt-edit-app php artisan optimize:clear

app-logs:
	docker logs --tail 500 prompt-edit-app

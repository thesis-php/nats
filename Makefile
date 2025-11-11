UID := $(shell id -u)
GID := $(shell id -g)

build:
	docker compose build --build-arg UID=$(UID) --build-arg GID=$(GID)

up: build
	docker compose up -d

down:
	docker compose down --remove-orphans

php:
	docker compose exec php bash

kv-list:
	docker run --rm --network host bitnamilegacy/natscli:latest --server localhost:4222 --user user --password Pswd1 kv ls

object-list:
	docker run --rm --network host bitnamilegacy/natscli:latest --server localhost:4222 --user user --password Pswd1 object ls

stream-list:
	docker run --rm --network host bitnamilegacy/natscli:latest --server localhost:4222 --user user --password Pswd1 stream ls

nats-help:
	docker run --rm --network host bitnamilegacy/natscli:latest --server localhost:4222 --user user --password Pswd1 --help

nats-latency:
	docker run --rm --network host bitnamilegacy/natscli:latest --server localhost:4222 --user user --password Pswd1 latency --server-b localhost:4223 --rate 500000

nats-stop:
	docker compose down nats-1 nats-2 nats-3

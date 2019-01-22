BASE = gone/php:cli
NAME = gone/sdkifier
DATE=`date +%Y-%m-%d`
USERID=$(shell id -u)

all: build push

clean:
	rm SDK/ -Rf

prepare:
	composer install
	docker pull $(BASE)

build: prepare clean
	docker build -t $(NAME):latest -f Dockerfile.SDKifier .

push:
	docker push $(NAME):latest

test-prepare:
	composer update -d tests/example-app
	rm -Rf tests/example-app/vendor/gone.io/zenderator
	rsync -ar --exclude vendor/ --exclude .git/ . tests/example-app/vendor/gone.io/zenderator
	docker-compose -f tests/docker-compose.yml -p zenderator-example build sut

test: test-prepare
	docker-compose -f tests/docker-compose.yml -p zenderator-example run sut ls -lah
	docker-compose -f tests/docker-compose.yml -p zenderator-example run sut vendor/bin/automize -h
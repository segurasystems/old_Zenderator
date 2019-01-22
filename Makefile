BASE = base/cli:branch_master
NAME = utils/sdkifier
DATE=$(shell date +%Y-%m-%d)
USERID=$(shell id -u)
GIT_SHORT_COMMIT=$(shell git rev-parse --short HEAD)

DEF_REPO=index.segura.cloud
AWS_REPO_LOOKUP=$(shell nslookup -type=cname index.segura.cloud | grep ".dkr.ecr.eu-west-1.amazonaws.com" -m 1 | awk '{print $$NF}' | sed 's/\.$$//')

ifndef REPO
ifneq ($(AWS_REPO_LOOKUP),)
REPO=$(AWS_REPO_LOOKUP)
else
REPO=$(DEF_REPO)
endif
endif

ifndef GIT_BRANCH
GIT_BRANCH:=$(shell git rev-parse --abbrev-ref HEAD)
endif
GIT_BRANCH_SAFE:=$(shell echo $(GIT_BRANCH)| sed 's/[^a-zA-Z0-9]/\_/g' | sed -e 's/\(.*\)/\L\1/')
ifndef BUILD_NUMBER
BUILD_NUMBER=local
endif

ifndef VERBOSE
.SILENT:
endif

all: build

clean:
	rm SDK/ -Rf

prepare:
	composer install
	docker pull $(REPO)/$(BASE)

build: prepare clean
	docker build --squash -t segura/$(NAME):build -f Dockerfile.SDKifier .

tag:
	docker tag segura/$(NAME):build $(REPO)/$(NAME):latest
	docker tag segura/$(NAME):build $(REPO)/$(NAME):$(DATE)
	docker tag segura/$(NAME):build $(REPO)/$(NAME):branch_$(GIT_BRANCH_SAFE)
	docker tag segura/$(NAME):build $(REPO)/$(NAME):branch_$(GIT_BRANCH_SAFE)_$(GIT_SHORT_COMMIT)
	docker tag segura/$(NAME):build $(REPO)/$(NAME):commit_$(GIT_SHORT_COMMIT)

push-to-repo:
	docker push $(REPO)/$(NAME):latest
	docker push $(REPO)/$(NAME):$(DATE)
	docker push $(REPO)/$(NAME):branch_$(GIT_BRANCH_SAFE)
	docker push $(REPO)/$(NAME):branch_$(GIT_BRANCH_SAFE)_$(GIT_SHORT_COMMIT)
	docker push $(REPO)/$(NAME):commit_$(GIT_SHORT_COMMIT)

push: build tag push-to-repo

test-prepare:
	composer update -d tests/example-app
	rm -Rf tests/example-app/vendor/segura/zenderator
	rsync -ar --exclude vendor/ --exclude .git/ . tests/example-app/vendor/segura/zenderator
	docker-compose -f tests/docker-compose.yml -p zenderator-example build sut

test: test-prepare
	docker-compose -f tests/docker-compose.yml -p zenderator-example run sut ls -lah
	docker-compose -f tests/docker-compose.yml -p zenderator-example run sut vendor/bin/automize -h
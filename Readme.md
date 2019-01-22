# Zenderator

## Overview
Zenderator is a mechanism for generating assets based upon a pre-existing schema. It is named as such because it is based heavilly upon [Zend DB](https://github.com/zendframework/zend-db). 

### Generated Assets
Zenderator optionally generates:

* Models
* Controllers
* Services
* Routes
* API Endpoints
* Dependency Injection entries for the above
* Unit tests (Models/Services/Api)

You can configure which of these assets it generates by configuring `zenderator.yml` inside your project.

### Code Standards

One of the other processes that Zenderator does, as part of its operation, is to enforce `psr2` and some other coding standards. 
It does this by running some automated scripts against code it has generated, as well as code written atop of its Models/Controllers/what-have-you.

On top of the PSR2 standards, a second suite of automated cleaning tools are run. These tools do things like:

* Remove un-used `use` statements from classes, and alphabetise the remainder.
* Align equals and double_arrows, for example, in large array blocks.
* Clean up PHPDocBlocks to match a standard introduced by Symfony.

## Basic Principles

Zenderators purpose is to do two key jobs:

* Provide generic Models/Controllers/Services/TableGateways and associated tests on to which new, more complicated functionality can be built upon.
* Provide a base App Model (`Zenderator\App`) on which to build a modern PHP web application, and to enable the developer to use whatever tool they deem fit for purpose ontop of that.

### Base App

Zenderator provides a base app from which other things can be built. Provided in this base are the following:

* Composer
* Slim
* Pimple DI
* Redis (through [Predis/Predis](https://github.com/nrk/predis))
* Twig
* Faker: A source of fake data. There is a provider for every piece of data you could imagine.
* MonoLog: Well-supported logging utility (which is piped into Redis by default)
* EventLoggerService: A slightly more complicated logger which is designed to catch changes to Models, and log which user altered the model. An example use is for tracking down whom made a breaking change.
* Differ: Partially related to EventLoggerService - This tool gives you a git style diff between two strings. 

With the exception of Composer, Slim & Pimple DI, any of these choices can be overridden or removed, by either replacing them in the DI, or simply not calling them from DI. Replacing an element in DI is as simple as extending the base app to create your own application container, and writing something else into whichever element in `$this->container[]` that you wish to remove.

### Model Generation

Models consist of two components. A Model object, and a TableGateway object, as per the Zend style. In this case, the model is only responsible for model data, and none of the marshalling of data into and out of the database. This marshalling responsibility is that of the TableGateway.

There are some convenience functions generated baked into the Model, such as `->save()` and `->destroy()` which are really just wrappers around the TableGateway functionality to do the same.

A `Model::factory()` function is provided, as well as Model setters being generated so that they return `$this` in context, so that setter chaining can be performed while creating a new object:

```
 $example = ExampleModel::factory()
   ->setThing(...)
   ->setDifferentThing(...)
   ->setAnother(...)
   ->save();
```

#### Schema interrogation

To generate Models and Services, the database must first be interrogated. For each table, a model muyst be created. For each model, each column is transformed into a property of that model. 

Properties that are marked as `Primary Key` in the database will be listed as a primary key within the model. A list of Primary Keys can be fetched with `Model::getPrimaryKeys()`.

Properties that are marked as `Auto Increment` must be handled separately, and are treated differently in some tests. For example, when saving an object with parameters that are null that are also AutoIncrement, we must write those fields back into the object post-save before handing back to the code that called the save. This way, upon save, the object is mutated to have the correct values from MySQL.

Properties that are used to relate between two objects will also have a `fetchXYZ()` function generated for it that will allow you to get that Related Model based on the id provided in the table relationship.  

#### Model/TableGateway/Service generation

// TODO

#### Generic Controllers

There is an implementation of a base Controller that all other controllers that Zenderator generates extends from. This provides a basic set of CRUD callbacks to the developer. The base functionality is in `Zenderator\Abstracts\CrudController` and provides the following functions, which are override-able in the concrete class implementation for that controller. 

* List: `GET /v1/example` Will fetch all instances of example, by default. Calls `Controller::listRequest`. 
* Create: `PUT /v1/example` Will create a new instance of example. Calls `Controller::createRequest`. 
* Get: `GET /v1/example/44` Will get instance #44, where id=44. Calls `Controller::getRequest`. 
* Delete: `DELETE /v1/example/44` Will delete instance #44. Calls `Controller::deleteRequest`. 

#### Test generation

Zenderator generates unit tests, ideally for 95%+ coverage of its own generated assets. The aspirational end-goal for generated assets is 100% coverage.
 
Zenderator tests are written in [PHPUnit](https://phpunit.de/manual/current/en/index.html). PHPUnit is a dependency of Zenderator.

All Zenderator tests should be extending from `Zenderator\Test\BaseTestCase`, or `Gone\AppCore\Test\RoutesTestCase` if the test needs to access an endpoint via the API for integration testing. 

Tests should endeavour to not leave garbage data in the database, or in any other storage (on disk, in redis for example) by cleaning up after themselves inside the `tearDown()` function. Zenderator tests achieve this by default, by running all SQL queries inside a transaction that is rolled back inside the test destructor. 
 
##### Running Tests

To run tests, by default, is very simple, assuming your phpunit.xml/phpunit.xml.dist is configured correctly.

    `./vendor/bin/phpunit`

##### API tests

The API can be tested without first starting a server and running curl requests against the API, as would have been traditional previously.
Instead, we can create [PSR7 Messages](http://www.php-fig.org/psr/psr-7/) and send those into the Slim Router, and have it respond in a manner similar to how it would for a connection into Apache.
This can be seen in `Gone\AppCore\Test\RoutesTestCase` as the `request()` function.

## Usage

To aid in using this, there is a tool called `Automize` included.

### Zenderator
To run zenderator, you can call Zenderator as shown:
    
    `./vendor/bin/zenderator`
    
or through Automize

    `./vendor/bin/automize -z
    or
    ./vendor/bin/automize --zenderator`
    
### Codebase Cleaner

There is an included tool to clean source code to match PSR2 standards, as well as do tasks like prune unneeded USE statements.
To run zenderator, you can call Zenderator as shown:
    
    `./vendor/bin/clean`
    
or through Automize

    `./vendor/bin/automize -c
    or
    ./vendor/bin/automize --clean`

### Automize Advanced

#### Run Zenderator, Clean code, run tests:

    `./vendor/bin/automize -zct --stop-on-error`

This instruction will run Zenderator, then run Clean, then run PHPUnit and will halt at the first fault.

This is equivalent to:

    `./vendor/bin/automize --zenderator --clean --tests-no-cover --stop-on-error`
    
## Project Commands

@TODO I need to write this bit.
    
## Additional Reading & Links

* [Faker](https://github.com/fzaninotto/Faker) - We use faker to generate test or dummy data, say when creating mock objects for tests.
* [Predis/Predis](https://github.com/nrk/predis)
* [PHPUnit](https://phpunit.de/manual/current/en/index.html)
* [PSR2 Coding Standards](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md) - An introduction to the PSR2 standard.
* [PSR4 Autoloader](http://www.php-fig.org/psr/psr-4/) - Autoloading format used by Zenderator and Co, only included here for completeness
* [PSR7 Messages](http://www.php-fig.org/psr/psr-7/) 
* [Twig](http://twig.sensiolabs.org/documentation) - We're using twig for genericized object templates.
* [Slim](http://www.slimframework.com/docs/)
* [Pimple Dependency Injection](http://pimple.sensiolabs.org/) - Pimple is used for our Dependency Injection, and comes for a ride with us as a dependency of Slim 3.x
* [Zend DB](https://github.com/zendframework/zend-db)
<?php
namespace Zenderator;

use Camel\CaseTransformer;
use Camel\Format;
use Segura\AppCore\App;
use Segura\AppCore\Router\Router;
use Slim\Http\Environment;
use Slim\Http\Headers;
use Slim\Http\Request;
use Slim\Http\RequestBody;
use Slim\Http\Response;
use Slim\Http\Uri;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Adapter\Adapter as DbAdaptor;
use Zend\Db\Metadata\Metadata;
use Zend\Stdlib\ConsoleHelper;
use Zenderator\Components\Model;
use Zenderator\Exception\SchemaToAdaptorException;

class Zenderator
{
    static $databaseConfigs;
    private $rootOfApp;
    private $config; // @todo rename $composerConfig
    private $composer;
    private $namespace;
    static private $useClassPrefixes = false;
    /** @var \Twig_Loader_Filesystem */
    private $loader;
    /** @var \Twig_Environment */
    private $twig;
    /** @var Adapter[] */
    private $adapters;
    /** @var Metadata[] */
    private $metadatas;
    private $ignoredTables;
    /** @var CaseTransformer */
    private $transSnake2Studly;
    /** @var CaseTransformer */
    private $transStudly2Camel;
    /** @var CaseTransformer */
    private $transStudly2Studly;
    /** @var CaseTransformer */
    private $transCamel2Studly;
    /** @var CaseTransformer */
    private $transSnake2Camel;
    /** @var CaseTransformer */
    private $transSnake2Spinal;
    /** @var CaseTransformer */
    private $transCamel2Snake;

    public function __construct(string $rootOfApp, array $databaseConfigs)
    {
        $this->rootOfApp = $rootOfApp;
        $this->setUp($databaseConfigs);
    }

    public static function classPrefixesOn()
    {
        self::$useClassPrefixes = true;
    }

    public static function classPrefixesOff()
    {
        self::$useClassPrefixes = false;
    }

    public static function isUsingClassPrefixes() : bool
    {
        return self::$useClassPrefixes;
    }

    private function setUp($databaseConfigs)
    {
        self::$databaseConfigs = $databaseConfigs;
        if (file_exists($this->rootOfApp . "/zenderator.yml")) {
            $zenderatorConfigPath = $this->rootOfApp . "/zenderator.yml";
        } elseif (file_exists($this->rootOfApp . "/zenderator.yml.dist")) {
            $zenderatorConfigPath = $this->rootOfApp . "/zenderator.yml.dist";
        } else {
            die("Missing Zenderator config /zenderator.yml or /zenderator.yml.dist\nThere is an example in /vendor/bin/segura/zenderator/zenderator.example.yml\n\n");
        }

        $this->config = file_get_contents($zenderatorConfigPath);
        $this->config = \Symfony\Component\Yaml\Yaml::parse($this->config);

        $this->composer = json_decode(file_get_contents($this->rootOfApp . "/composer.json"));
        $namespaces = array_keys((array)$this->composer->autoload->{'psr-4'});
        $this->namespace = rtrim($namespaces[0], '\\');

        $this->loader = new \Twig_Loader_Filesystem(__DIR__ . "/../generator/templates");
        $this->twig = new \Twig_Environment($this->loader);

        $this->twig->addExtension(
            new \Segura\AppCore\Twig\Extensions\ArrayUniqueTwigExtension()
        );

        $fct = new \Twig_SimpleFunction('var_export', 'var_export');
        $this->twig->addFunction($fct);

        $this->ignoredTables = [
            'tbl_migration',
        ];

        $this->transSnake2Studly = new CaseTransformer(new Format\SnakeCase(), new Format\StudlyCaps());
        $this->transStudly2Camel = new CaseTransformer(new Format\StudlyCaps(), new Format\CamelCase());
        $this->transStudly2Studly = new CaseTransformer(new Format\StudlyCaps(), new Format\StudlyCaps());
        $this->transCamel2Studly = new CaseTransformer(new Format\CamelCase(), new Format\StudlyCaps());
        $this->transSnake2Camel = new CaseTransformer(new Format\SnakeCase(), new Format\CamelCase());
        $this->transSnake2Spinal = new CaseTransformer(new Format\SnakeCase(), new Format\SpinalCase());
        $this->transCamel2Snake = new CaseTransformer(new Format\CamelCase(), new Format\SnakeCase());

        // Check for old-style config.
        if (isset($databaseConfigs['driver']) || isset($databaseConfigs['hostname'])) {
            die("Database configs have changed in Zenderator!\nYou need to update your mysql.php config!\n\n");
        }

        // Decide if we're gonna use class prefixes. You don't want to do this if you have a single DB,
        // or you'll get classes called DefaultThing instead of just Thing.
        if (isset($databaseConfigs['Default']) && count($databaseConfigs) == 1) {
            self::classPrefixesOff();
        } else {
            self::classPrefixesOn();
        }

        foreach ($databaseConfigs as $dbName => $databaseConfig) {
            $this->adapters[$dbName] = new DbAdaptor($databaseConfig);
            $this->metadatas[$dbName] = new Metadata($this->adapters[$dbName]);
            $this->adapters[$dbName]->query('set global innodb_stats_on_metadata=0;');
        }
    }

    public static function schemaName2databaseName($schemaName)
    {
        foreach (self::$databaseConfigs as $dbName => $databaseConfig) {
            $adapter = new DbAdaptor($databaseConfig);
            if ($schemaName == $adapter->getCurrentSchema()) {
                return $dbName;
            }
        }
        throw new SchemaToAdaptorException("Could not translate {$schemaName} to an appropriate dbName");
    }

    public static function getAutoincrementColumns(DbAdaptor $adapter, $table)
    {
        $sql = "SHOW columns FROM `{$table}` WHERE extra LIKE '%auto_increment%'";
        $query = $adapter->query($sql);
        $columns = [];

        foreach ($query->execute() as $aiColumn) {
            $columns[] = $aiColumn['Field'];
        }
        return $columns;
    }

    public function makeZenderator($cleanByDefault = false)
    {
        $models = $this->makeModelSchemas();
        $this->removeCoreGeneratedFiles();
        $this->makeCoreFiles($models);
        if($cleanByDefault) {
            $this->cleanCode();
        }
    }

    private function makeModelSchemas()
    {
        /** @var Model[] $models */
        $models = [];
        foreach ($this->adapters as $dbName => $adapter) {
            echo "Adaptor: {$dbName}\n";
            /**
             * @var $tables \Zend\Db\Metadata\Object\TableObject[]
             */
            $tables = $this->metadatas[$dbName]->getTables();

            echo "Collecting " . count($tables) . " entities data.\n";

            foreach ($tables as $table) {
                $oModel = Components\Model::Factory()
                    ->setNamespace($this->namespace)
                    ->setAdaptor($adapter)
                    ->setDatabase($dbName)
                    ->setTable($table->getName())
                    ->computeColumns($table->getColumns())
                    ->computeConstraints($table->getConstraints());
                $models[$oModel->getClassName()] = $oModel;
            }
        }

        // Scan for remote relations
        //\Kint::dump(array_keys($models));
        foreach ($models as $oModel) {
            $oModel->scanForRemoteRelations($models);
        }

        // Check for Conflicts.
        $conflictCheck = [];
        foreach ($models as $oModel) {
            if (count($oModel->getRemoteObjects()) > 0) {
                foreach ($oModel->getRemoteObjects() as $remoteObject) {
                    #echo "Base{$remoteObject->getLocalClass()}Model::fetch{$remoteObject->getRemoteClass()}Object\n";
                    if (!isset($conflictCheck[$remoteObject->getLocalClass()][$remoteObject->getRemoteClass()])) {
                        $conflictCheck[$remoteObject->getLocalClass()][$remoteObject->getRemoteClass()] = $remoteObject;
                    } else {
                        $conflictCheck[$remoteObject->getLocalClass()][$remoteObject->getRemoteClass()]->markClassConflict(true);
                        $remoteObject->markClassConflict(true);
                    }
                }
            }
        }

        // Bit of Diag...
        #foreach($models as $oModel){
        #    if(count($oModel->getRemoteObjects()) > 0) {
        #        foreach ($oModel->getRemoteObjects() as $remoteObject) {
        #            echo " > {$oModel->getClassName()} has {$remoteObject->getLocalClass()} on {$remoteObject->getLocalBoundColumn()}:{$remoteObject->getRemoteBoundColumn()} (Function: {$remoteObject->getLocalFunctionName()})\n";
        #        }
        #    }
        #}

        // Finally return some models.
        return $models;
    }

    private function removeCoreGeneratedFiles(){
        $generatedPaths = [
            APP_ROOT . "/src/Controllers/Base/",
            APP_ROOT . "/src/Models/Base/",
            APP_ROOT . "/src/Routes/Generated/",
            APP_ROOT . "/src/Services/Base/",
            APP_ROOT . "/src/TableGateways/Base/",
            APP_ROOT . "/tests/Api/Generated/",
            APP_ROOT . "/tests/Models/Generated/",
            APP_ROOT . "/tests/Services/Generated/",
        ];
        foreach($generatedPaths as $generatedPath){
            foreach(new \DirectoryIterator($generatedPath) as $file){
                if(!$file->isDot() && $file->getExtension() == 'php') {
                    unlink($file->getRealPath());
                }
            }
        }
    }
    /**
     * @param Model[] $models
     */
    private function makeCoreFiles(array $models)
    {
        echo "Generating Core files for " . count($models) . " models... \n";
        $allModelData = [];
        foreach ($models as $model) {
            $allModelData[$model->getClassName()] = $model->getRenderDataset();
            // "Model" suite
            echo " > {$model->getClassName()}\n";

            #\Kint::dump($model->getRenderDataset());
            if (in_array("Models", $this->config['templates'])) {
                $this->renderToFile(true, APP_ROOT . "/src/Models/Base/Base{$model->getClassName()}Model.php", "Models/basemodel.php.twig", $model->getRenderDataset());
                $this->renderToFile(false, APP_ROOT . "/src/Models/{$model->getClassName()}Model.php", "Models/model.php.twig", $model->getRenderDataset());
                $this->renderToFile(true, APP_ROOT . "/tests/Models/Generated/{$model->getClassName()}Test.php", "Models/tests.models.php.twig", $model->getRenderDataset());
                $this->renderToFile(true, APP_ROOT . "/src/TableGateways/Base/Base{$model->getClassName()}TableGateway.php", "Models/basetable.php.twig", $model->getRenderDataset());
                $this->renderToFile(false, APP_ROOT . "/src/TableGateways/{$model->getClassName()}TableGateway.php", "Models/table.php.twig", $model->getRenderDataset());
            }

            // "Service" suite
            if (in_array("Services", $this->config['templates'])) {
                $this->renderToFile(true, APP_ROOT . "/src/Services/Base/Base{$model->getClassName()}Service.php", "Services/baseservice.php.twig", $model->getRenderDataset());
                $this->renderToFile(false, APP_ROOT . "/src/Services/{$model->getClassName()}Service.php", "Services/service.php.twig", $model->getRenderDataset());
                $this->renderToFile(true, APP_ROOT . "/tests/Services/Generated/{$model->getClassName()}Test.php", "Services/tests.service.php.twig", $model->getRenderDataset());
            }

            // "Controller" suite
            if (in_array("Controllers", $this->config['templates'])) {
                $this->renderToFile(true, APP_ROOT . "/src/Controllers/Base/Base{$model->getClassName()}Controller.php", "Controllers/basecontroller.php.twig", $model->getRenderDataset());
                $this->renderToFile(false, APP_ROOT . "/src/Controllers/{$model->getClassName()}Controller.php", "Controllers/controller.php.twig", $model->getRenderDataset());
            }

            // "Endpoint" test suite
            if (in_array("Endpoints", $this->config['templates'])) {
                $this->renderToFile(true, APP_ROOT . "/tests/Api/Generated/{$model->getClassName()}EndpointTest.php", "ApiEndpoints/tests.endpoints.php.twig", $model->getRenderDataset());
            }

            // "Routes" suit
            if (in_array("Routes", $this->config['templates'])) {
                $this->renderToFile(true, APP_ROOT . "/src/Routes/Generated/{$model->getClassName()}Route.php", "Router/route.php.twig", $model->getRenderDataset());
            }
        }

        echo "Generating App Container:";
        $this->renderToFile(true, APP_ROOT . "/src/AppContainer.php", "DependencyInjector/appcontainer.php.twig", ['models' => $allModelData, 'config' => $this->config]);
        echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n\n";

        // "Routes" suit
        if (in_array("Routes", $this->config['templates'])) {
            echo "Generating Router:";
            $this->renderToFile(true, APP_ROOT . "/src/Routes.php", "Router/routes.php.twig", [
                'models' => $allModelData,
                'app_container' => APP_CORE_NAME,
            ]);
            echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n\n";
        }
    }

    private function renderToFile(bool $overwrite, string $path, string $template, array $data)
    {
        $output = $this->twig->render($template, $data);
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        if (!file_exists($path) || $overwrite) {
            #echo "  > Writing to {$path}\n";
            file_put_contents($path, $output);
        }
    }

    private function removePHPVCRCassettes($outputPath)
    {
        $cassettesDir = new \DirectoryIterator($outputPath . "/tests/fixtures/");
        foreach($cassettesDir as $cassette){
            if(!$cassette->isDot()){
                if(substr($cassette->getFilename(), -9, 9) == '.cassette') {
                    unlink($cassette->getPathname());
                }
            }
        }
    }

    public function cleanCode()
    {
        if (is_array($this->config['formatting']) && in_array("clean", $this->config['formatting'])) {
            $this->cleanCodePHPCSFixer();
        }
        if (is_array($this->config['formatting']) && in_array("clean", $this->config['formatting'])) {
            $this->cleanCodePSR2();
        }
        $this->cleanCodeComposerAutoloader();
    }

    private function cleanCodePHPCSFixer()
    {
        require(__DIR__ . "/../generator/phpcsfixerfier");
    }

    private function cleanCodePSR2()
    {
        require(__DIR__ . "/../generator/psr2ifier");
    }

    public function cleanCodeComposerAutoloader()
    {
        $begin = microtime(true);
        echo "Optimising Composer Autoloader... \n";
        exec("composer dump-autoload -o");
        $time = microtime(true) - $begin;
        echo "\n[Complete in " . number_format($time, 2) . "]\n";
    }

    public function runTests($withCoverage = false)
    {
        echo "Running phpunit... \n";

        if($withCoverage) {
            passthru("./vendor/bin/phpunit");
        }else{
            passthru("./vendor/bin/phpunit --no-coverage");
        }
        sleep(3);
    }

    public function updateSeguraDependencies()
    {
        $composerJson = json_decode(file_get_contents(APP_ROOT . "/composer.json"),true);
        $dependencies = array_merge($composerJson['require'], $composerJson['require-dev']);
        $toUpdate = [];
        foreach($dependencies as $dependency => $version){
            if(substr($dependency, 0, strlen("segura/")) == "segura/"){
                $toUpdate[] = $dependency;
            }
        }
        $begin = microtime(true);
        echo "Updating Segura Composer Dependencies... \n";
        foreach($toUpdate as $item){
            echo " > {$item}\n";
        }
        exec("composer update " . implode(" ", $toUpdate));
        $time = microtime(true) - $begin;
        echo "\n[Complete in " . number_format($time, 2) . "]\n";
    }

    public function makeSDK($outputPath = APP_ROOT, $cleanByDefault = true)
    {
        $models = $this->makeModelSchemas();
        $this->makeSDKFiles($models, $outputPath);
        $this->removePHPVCRCassettes($outputPath);
        if($cleanByDefault) {
            $this->cleanCode();
        }
    }

    private function makeSDKFiles($models, $outputPath = APP_ROOT)
    {
        $packs = [];
        $routeCount = 0;
        $sharedRenderData = [
            'app_name' => APP_NAME,
            'app_container' => APP_CORE_NAME,
            'default_base_url' => strtolower("http://" . APP_NAME . ".segurasystems.dev"),
        ];

        $routes = $this->getRoutes();

        foreach ($routes as $route) {
            if ($route['name']) {
                if (isset($route['class'])) {
                    $packs[$route['class']][$route['function']] = $route;
                    $routeCount++;
                } else {
                    echo " > Skipping {$route['name']} because there is no defined Class attached to it...\n";
                }
            }
        }

        echo "Generating SDK for {$routeCount} routes...\n";
        // "SDK" suite
        foreach ($packs as $packName => $routes) {
            echo " > Pack: {$packName}...\n";
            $routeRenderData = [
                'pack_name' => $packName,
                'routes' => $routes,
            ];
            $properties = [];
            foreach ($routes as $route) {
                if(isset($route['properties'])) {
                    foreach ($route['properties'] as $property) {
                        $properties[] = $property;
                    }
                }
            }
            $properties = array_unique($properties);
            $routeRenderData['properties'] = $properties;

            $routeRenderData = array_merge($sharedRenderData, $routeRenderData);
            #\Kint::dump($routeRenderData);

            // Access Layer
            $this->renderToFile(true, $outputPath . "/src/AccessLayer/{$packName}AccessLayer.php", "SDK/AccessLayer/accesslayer.php.twig", $routeRenderData);
            $this->renderToFile(true, $outputPath . "/src/AccessLayer/Base/Base{$packName}AccessLayer.php", "SDK/AccessLayer/baseaccesslayer.php.twig", $routeRenderData);

            // Models
            $this->renderToFile(true, $outputPath . "/src/Models/Base/Base{$packName}Model.php", "SDK/Models/basemodel.php.twig", $routeRenderData);
            $this->renderToFile(true, $outputPath . "/src/Models/{$packName}Model.php", "SDK/Models/model.php.twig", $routeRenderData);

            // Tests
            $this->renderToFile(true, $outputPath . "/tests/AccessLayer/{$packName}Test.php", "SDK/Tests/AccessLayer/client.php.twig", $routeRenderData);

            if (!file_exists($outputPath . "/tests/fixtures")) {
                mkdir($outputPath . "/tests/fixtures", null, true);
            }
        }

        $renderData = array_merge(
            $sharedRenderData,
            [
                'packs' => $packs,
                'config' => $this->config
            ]
        );

        echo "Generating Abstract Objects:";
        $this->renderToFile(true, $outputPath . "/src/Abstracts/AbstractAccessLayer.php", "SDK/Abstracts/abstractaccesslayer.php.twig", $renderData);
        $this->renderToFile(true, $outputPath . "/src/Abstracts/AbstractClient.php", "SDK/Abstracts/abstractclient.php.twig", $renderData);
        $this->renderToFile(true, $outputPath . "/src/Abstracts/AbstractModel.php", "SDK/Abstracts/abstractmodel.php.twig", $renderData);
        echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n";

        echo "Generating Filter Objects:";
        $this->renderToFile(true, $outputPath . "/src/Filters/Filter.php", "SDK/Filters/filter.php.twig", $renderData);
        $this->renderToFile(true, $outputPath . "/src/Filters/FilterCondition.php", "SDK/Filters/filtercondition.php.twig", $renderData);
        echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n";

        echo "Generating Client Container:";
        $this->renderToFile(true, $outputPath . "/src/Client.php", "SDK/client.php.twig", $renderData);
        echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n";

        echo "Generating Composer.json:";
        $this->renderToFile(true, $outputPath . "/composer.json", "SDK/composer.json.twig", $renderData);
        echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n";

        echo "Generating Test Bootstrap:";
        $this->renderToFile(true, $outputPath . "/bootstrap.php", "SDK/bootstrap.php.twig", $renderData);
        echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n";

        echo "Generating phpunit.xml, documentation, etc:";
        $this->renderToFile(true, $outputPath . "/phpunit.xml.dist", "SDK/phpunit.xml.twig", $renderData);
        $this->renderToFile(true, $outputPath . "/Readme.md", "SDK/readme.md.twig", $renderData);
        $this->renderToFile(true, $outputPath . "/.gitignore", "SDK/gitignore.twig", $renderData);
        $this->renderToFile(true, $outputPath . "/Dockerfile", "SDK/Dockerfile.twig", $renderData);
        $this->renderToFile(true, $outputPath . "/test-compose.yml", "SDK/docker-compose.yml.twig", $renderData);
        $this->renderToFile(true, $outputPath . "/run-tests.sh", "SDK/run-tests.sh.twig", $renderData);
        chmod($outputPath . "/run-tests.sh", 0755);
        echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n";

        echo "Generating Exceptions:";
        $derivedExceptions = [
            'ObjectNotFoundException',
            'FilterConditionNotFoundException',
        ];
        foreach ($derivedExceptions as $derivedException) {
            $this->renderToFile(true, $outputPath . "/src/Exceptions/{$derivedException}.php", "SDK/Exceptions/DerivedException.php.twig", array_merge($renderData, ['ExceptionName' => $derivedException]));
        }
        $this->renderToFile(true, $outputPath . "/src/Exceptions/SDKException.php", "SDK/Exceptions/SDKException.php.twig", $renderData);
        echo " [" . ConsoleHelper::COLOR_GREEN . "DONE" . ConsoleHelper::COLOR_RESET . "]\n";

        #\Kint::dump($renderData);
    }

    private function getRoutes()
    {
        $response = $this->makeRequest("GET", "/v1");
        $body = (string)$response->getBody();
        $body = json_decode($body, true);
        return $body['Routes'];
    }

    /**
     * @param string $method
     * @param string $path
     * @param array  $post
     * @param bool   $isJsonRequest
     *
     * @return Response
     */
    private function makeRequest(string $method, string $path, $post = null, $isJsonRequest = true)
    {
        /**
         * @var \Slim\App           $app
         * @var \Segura\AppCore\App $applicationInstance
         */
        $applicationInstance = App::Instance();
        $calledClass = get_called_class();

        $app = $applicationInstance->getApp();

        if (defined("$calledClass")) {
            $modelName = $calledClass::MODEL_NAME;
            require(APP_ROOT . "/src/Routes/{$modelName}Route.php");
        } else {
            require(APP_ROOT . "/src/Routes.php");
        }
        require(APP_ROOT . "/src/RoutesExtra.php");

        Router::Instance()->populateRoutes($app);

        $env = Environment::mock(
            [
                'SCRIPT_NAME' => '/index.php',
                'REQUEST_URI' => $path,
                'REQUEST_METHOD' => $method,
                'RAND' => rand(0, 100000000),
            ]
        );
        $uri = Uri::createFromEnvironment($env);
        $headers = Headers::createFromEnvironment($env);

        $cookies = [];
        $serverParams = $env->all();
        $body = new RequestBody();
        if (!is_array($post) && $post != null) {
            $body->write($post);
            $body->rewind();
        } elseif (is_array($post) && count($post) > 0) {
            $body->write(json_encode($post));
            $body->rewind();
        }
        $request = new Request($method, $uri, $headers, $cookies, $serverParams, $body);
        if ($isJsonRequest) {
            $request = $request->withHeader("Content-type", "application/json");
            $request = $request->withHeader("Accept", "application/json");
        }
        $response = new Response();
        // Invoke app
        $app($request, $response);
        #echo "\nRequesting {$method}: {$path} : ".json_encode($post) . "\n";
        #echo "Response: " . (string) $response->getBody()."\n";

        return $response;
    }
}

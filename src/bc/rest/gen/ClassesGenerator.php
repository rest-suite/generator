<?php

namespace bc\rest\gen;

use gossi\codegen\model\PhpClass;
use gossi\codegen\model\PhpMethod;
use gossi\codegen\model\PhpParameter;
use gossi\codegen\model\PhpProperty;
use gossi\docblock\Docblock;
use gossi\docblock\tags\TagFactory;
use gossi\swagger\Operation;
use gossi\swagger\Parameter;
use gossi\swagger\Path;
use gossi\swagger\SecurityScheme;
use gossi\swagger\Swagger;
use Symfony\Component\Yaml\Yaml;

class ClassesGenerator
{

    /**
     * @var Swagger
     */
    private $swagger;
    /**
     * @var string
     */
    private $namespace;
    /**
     * @var ModelGenerator
     */
    private $models;
    /**
     * @var ControllerGenerator
     */
    private $controllers;
    /**
     * @var array
     */
    private $groups;
    /**
     * @var array
     */
    private $configs;
    /**
     * @var PhpClass
     */
    private $bootstrap;

    /** @var  PhpClass $auth */
    private $auth;

    /** @var  array $authAdditionalClasses */
    private $authAdditionalClasses;


    /**
     * ClassesGenerator constructor.
     *
     * @param string $swaggerFile
     * @param string $namespace
     * @param array $changedControllers
     */
    public function __construct($swaggerFile, $namespace, $changedControllers = [])
    {
        $yml = file_get_contents($swaggerFile);
        $swagger = Yaml::parse($yml);
        $this->swagger = new Swagger($swagger);
        $this->namespace = rtrim($namespace, '\\');
        $this->models = new ModelGenerator($this->swagger, $this->namespace);
        $this->configs = [];
        $this->createPathGroups();
        $this->controllers = new ControllerGenerator($this->swagger, $this->namespace, $this->groups);
        $this->auth = null;

        if(!empty($changedControllers)){
            foreach ($changedControllers as $name => $changedController) {
                if(!empty($changedController)){
                    foreach ($changedController as $methodName => $body) {
                        if(!empty($body)){
                            $this->controllers->get($name)->getMethod($methodName)->setBody($body);
                        }
                    }
                }
            }
        }

        $isSecurityGlobalValid = $this->swagger->getSecurity()->valid();
        $isSecurityInPathValid =  AuthGenerator::isThereSecurityInPaths($this->swagger);

        if($isSecurityGlobalValid || $isSecurityInPathValid) {

            /** @var SecurityScheme $securityDefinition **/
            foreach ($this->swagger->getSecurityDefinitions() as $securityDefinition) {
                if(!in_array($securityDefinition->getType(), AuthGenerator::getAuthAvailableTypes())) {
                    throw new \InvalidArgumentException('Wrong auth type: ' . $securityDefinition->getType() . ", available types: "
                    . implode(', ',  AuthGenerator::getAuthAvailableTypes()));
                }
            };

            $authArr = AuthGenerator::createAuth($this->namespace, $this->swagger);
            $this->auth = $authArr['Auth'];
            unset($authArr['Auth']);

            $this->authAdditionalClasses = $authArr;

        }


        $this->createBootstrap();
    }

    /**
     * @return array
     */
    public function getAuthAdditionalClasses(): array
    {
        return $this->authAdditionalClasses;
    }

    /**
     * @return PhpClass|null
     */
    public function getAuth()
    {
        return $this->auth;
    }

    private function createPathGroups()
    {
        $paths = $this->swagger->getPaths();

        $this->groups = [];

        /** @var Path $path */
        foreach ($paths as $path) {
            $elements = explode('/', ltrim($path->getPath(), '/'));
            if (count($elements) == 0) {
                $elements[0] = 'default';
            }
            if (!isset($this->groups[$elements[0]])) $groups[$elements[0]] = [];

            foreach (Swagger::$METHODS as $method) {
                if ($path->hasOperation($method)) {
                    /** @var Operation $operation */
                    $operation = $path->getOperation($method);
                    $this->groups[$elements[0]][] = [
                        'method' => $method,
                        'operation' => $operation,
                        'path' => $path,
                    ];
                }
            }
        }
    }

    private function createBootstrap()
    {
        $bootstrap = new PhpClass('Bootstrap');
        $bootstrap
            ->setNamespace($this->namespace)
            ->setParentClassName("AbstractBootstrap")
            ->addUseStatement("Rest\\Lib\\AbstractBootstrap")
            ->setDescription('Class Bootstrap')
            ->setLongDescription('Creating routes and starting application')
            ->setDocblock(Docblock::create()->appendTag(TagFactory::create('package', $this->namespace)));


        if(!is_null($this->auth)){

            $construct = new PhpMethod('__construct');

            $constructBody[] = 'parent::__construct(null);';
            $constructBody[] = '$auth = new Auth($this->getApp());';
            $constructBody[] = '$auth->checkAuth();';

            $construct->setBody(implode("\n", $constructBody));

            $bootstrap
                ->setMethod($construct)
                ->addUseStatement(
                    $this->namespace . '\\Auth\\Auth'
                )
            ;
        }

        $setRoutes = PhpMethod::create('setUpRoutes')->setDescription('Setup routes. Generated');

        $routes = [];

        foreach ($this->groups as $group => $info) {
            $ctrl = ucfirst(strtolower($group)) . 'Controller';
            $controller = $this->controllers->get($ctrl);
            $routeMethod = PhpMethod::create('routeTo' . $controller->getName());
            $path = rtrim($this->swagger->getBasePath(), '/') . '/' . $group;
            $routeMethod
                ->setVisibility('private')
                ->setDescription('Route to ' . $path . ' api group');
            $body = [];

            $defaultConfig = [];

            $subBody = [];
            $subBody[] = 'function () use ($bootstrap) {';
            foreach ($info as $item) {
                /** @var Path $currentPath */
                $currentPath = $item['path'];
                /** @var Operation $op */
                $op = $item['operation'];
                $p = str_replace('/' . $group, '', $currentPath->getPath());
                if ($currentPath->getParameters()->size() > 0) {
                    /**
                     * @var string $key
                     * @var Parameter $val
                     */
                    foreach ($currentPath->getParameters() as $val) {
                        if ($val->getIn() == 'path'
                            && strpos($p, $val->getName()) !== false
                            && !empty($val->getPattern())
                        ) {
                            $p = str_replace(
                                '{' . $val->getName() . '}',
                                '{' . $val->getName() . ':' . trim($val->getPattern(), '/') . '}', $p);
                        }
                    }
                }
                $method = strtolower($item['method']);
                $subBody[] = "\t\$bootstrap->addRoute('" . $method . "', '" . $p . "', '\\" . $controller->getQualifiedName() . ':' . $op->getOperationId() . '\');';
                $defaultConfig[$op->getOperationId()] = true;
            }
            $subBody[] = '}';

            $this->configs[$controller->getQualifiedName()] = $defaultConfig;

            $body[] = '$bootstrap = $this;';
            $body[] = '$this->getApp()->group(\'' . $path . '\', ' . implode("\n", $subBody) . ');';

            $routeMethod->setBody(implode("\n", $body));

            $routes[] = '$this->' . $routeMethod->getName() . '();';
            $bootstrap->setMethod($routeMethod);


        }

        $loadConfigs = [
            '$result[\'api\'] = [];',
            '$result[\'api\'] = array_merge($result[\'api\'], $this->loadConfig(\'/app/config/api.php\'));',
            'return $result;'
        ];
        
        $bootstrap->setMethod(
            PhpMethod::create('defaultSettings')
                ->setBody('return '.var_export($this->getConfigs(), true).';')
                ->setType("array")
        );

        $bootstrap->setMethod(PhpMethod::create('loadConfigs')->setBody(implode("\n", $loadConfigs)));

        $setRoutes->setBody(implode("\n", $routes));

        $bootstrap->setMethod($setRoutes);

        /** @var PhpMethod $getInfo */
        $getInfo =
            PhpMethod::create('getInfo')
                ->setStatic(true)
                ->setBody('return ' . var_export($this->swagger->getInfo()->toArray(), true) . ';');
        
        $getInfo->setType("array");
        $getInfo->setDescription('Return generated info from specs');

        $bootstrap->setMethod($getInfo);

        $this->bootstrap = $bootstrap;
    }

    /**
     * @return array
     */
    public function getConfigs()
    {
        return $this->configs;
    }

    public static function getType($type)
    {
        switch ($type) {
            case Swagger::T_INTEGER:
                return 'int';
            case Swagger::T_NUMBER:
                return 'int';
            case Swagger::T_BOOLEAN:
                return 'bool';
            case Swagger::T_STRING:
                return 'string';
            case 'array':
                return 'array';
            case 'file':
                return 'UploadedFile';
            default:
                return $type;
        }
    }

    /**
     * @return PhpClass[]
     */
    public function getControllers()
    {
        return $this->controllers->getAll();
    }

    /**
     * @return PhpClass
     */
    public function getBootstrap()
    {
        return $this->bootstrap;
    }

    /**
     * @return PhpClass[]
     */
    public function getModels()
    {
        return $this->models->getAll();
    }
}
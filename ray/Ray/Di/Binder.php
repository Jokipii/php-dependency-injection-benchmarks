<?php
namespace Ray\Di;

use Aura\Di\ConfigInterface;
use Ray\Aop\Bind;
use Ray\Aop\Compiler;
use Ray\Di\Definition;
use Ray\Di\Exception;

final class Binder
{
    /**
     * @var AbstractModule
     */
    private $module;

    /**
     * @var InjectorInterface
     */
    private $injector;

    /**
     * @var \Aura\Di\ConfigInterface
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var string
     */
    private $class;


    public function __construct(
        AbstractModule $module,
        InjectorInterface $injector,
        ConfigInterface $config,
        LoggerInterface $logger = null
    ) {
        $this->module = $module;
        $this->injector = $injector;
        $this->config = $config;
        $this->container = $injector->getContainer();
        $this->logger = $logger;
    }

    /**
     * Return method bound setting
     *
     * @param AbstractModule $module
     * @param string         $class
     * @param array          $setterDefinition
     *
     * @return array
     */
    public function bindMethod(AbstractModule $module, $class, array $setterDefinition)
    {
        $this->module = $module;
        $this->class = $class;
        list($method, $settings) = each($setterDefinition);
        // Set one parameter with definition, or JIT binding.
        foreach ($settings as $key => &$param) {
            $param = $this->extractParam($param, $key);
        }

        return [$method, $settings];
    }

    /**
     * Extract parameter as defined
     *
     * @param array  $param
     * @param string $key
     *
     * @return array
     */
    private function extractParam(array $param, $key)
    {
        $annotate = $param[Definition::PARAM_ANNOTATE];
        $typeHint = $param[Definition::PARAM_TYPEHINT];
        $hasTypeHint =  isset($this->module[$typeHint][$annotate]) &&  isset($this->module[$typeHint][$annotate]) && ($this->module[$typeHint][$annotate] !== []);
        $binding = $hasTypeHint ? $this->module[$typeHint][$annotate] : false;
        $hasNoBound = $binding === false || isset($binding[AbstractModule::TO]) === false;
        if ($hasNoBound) {
            return $this->getNoBoundParam($param, $key);
        }

        return $this->getParam($param, $binding);
    }

    /**
     * @param array  $param
     * @param string $key
     *
     * @return array
     */
    private function getNoBoundParam(array $param, $key)
    {
        if (array_key_exists(Definition::DEFAULT_VAL, $param)) {

            return $param[Definition::DEFAULT_VAL];
        }
        $binding = $this->jitBinding($param, $param[Definition::PARAM_TYPEHINT], $param[Definition::PARAM_ANNOTATE], $key);
        $param = $this->getParam($param, $binding);

        return $param;
    }

    /**
     * @param array $param
     * @param array $binding
     *
     * @return array
     */
    private function getParam(array $param, array $binding)
    {
        list($bindingToType, $target) = $binding[AbstractModule::TO];

        list($param, $bound) = $this->instanceBound($param, $bindingToType, $target, $binding);
        if ($bound) {
            return $param;
        }
        $param = $this->extractNotBoundParam($param[Definition::PARAM_TYPEHINT], $bindingToType, $target);

        return $param;
    }

    /**
     * Return param when not bound
     *
     * @param string $typeHint
     * @param string $bindingToType
     * @param string $target
     *
     * @return array
     */
    private function extractNotBoundParam($typeHint, $bindingToType, $target)
    {
        if ($typeHint === '') {
            $param = $this->getInstanceWithContainer(Scope::PROTOTYPE, $bindingToType, $target);

            return $param;
        }
        $param = $this->typeBound($typeHint, $bindingToType, $target);

        return $param;

    }

    /**
     * Set param by type bound
     *
     * @param string $typeHint
     * @param string $bindingToType
     * @param string $target
     *
     * @return mixed
     */
    private function typeBound($typeHint, $bindingToType, $target)
    {
        list(, , $definition) = $this->config->fetch($typeHint);
        $in = isset($definition[Definition::SCOPE]) ? $definition[Definition::SCOPE] : Scope::PROTOTYPE;
        $param = $this->getInstanceWithContainer($in, $bindingToType, $target);

        return $param;
    }
    /**
     * Set param by instance bound(TO_INSTANCE, TO_CALLABLE, or already set in container)
     *
     * @param array  $param
     * @param string $bindingToType
     * @param mixed  $target
     * @param mixed  $binding
     *
     * @return array [$param, $isBound]
     */
    private function instanceBound($param, $bindingToType, $target, $binding)
    {
        if ($bindingToType === AbstractModule::TO_INSTANCE) {
            return [$target, true];
        }

        if ($bindingToType === AbstractModule::TO_CALLABLE) {
            /* @var $target \Closure */

            return [$target(), true];
        }

        if (isset($binding[AbstractModule::IN])) {
            $param = $this->getInstanceWithContainer($binding[AbstractModule::IN], $bindingToType, $target);

            return [$param, true];
        }

        return [$param, false];
    }


    /**
     * JIT binding
     *
     * @param array  $param
     * @param string $typeHint
     * @param string $annotate
     * @param string $key
     *
     * @return array
     * @throws Exception\OptionalInjectionNotBound
     * @throws Exception\NotBound
     */
    private function jitBinding(array $param, $typeHint, $annotate, $key)
    {
        $typeHintBy = $param[Definition::PARAM_TYPEHINT_BY];
        if ($typeHintBy == []) {
            throw $this->getNotBoundException($param, $key, $typeHint, $annotate);
        }
        if ($typeHintBy[0] === Definition::PARAM_TYPEHINT_METHOD_IMPLEMETEDBY) {
            return [AbstractModule::TO => [AbstractModule::TO_CLASS, $typeHintBy[1]]];
        }

        return [AbstractModule::TO => [AbstractModule::TO_PROVIDER, $typeHintBy[1]]];
    }

    /**
     * Get instance with container
     *
     * @param string $in            Scope::SINGLETON | Scope::PROTOTYPE
     * @param string $bindingToType AbstractModule::TO_CLASS | AbstractModule::TO_PROVIDER ...
     * @param mixed  $target        target interface or class
     *
     * @return mixed
     */
    public function getInstanceWithContainer($in, $bindingToType, $target)
    {
        if ($in === Scope::SINGLETON && $this->container->has($target)) {
            $instance = $this->container->get($target);

            return $instance;
        }
        $isToClassBinding = ($bindingToType === AbstractModule::TO_CLASS);
        $instance = $isToClassBinding ? $this->injector->getInstance($target) : $this->getProvidedInstance($target);

        if ($in === Scope::SINGLETON) {
            $this->container->set($target, $instance);
        }

        return $instance;
    }

    /**
     * @param string $target interface name
     *
     * @return Compiler
     */
    private function getProvidedInstance($target)
    {
        $provider = $this->injector->getInstance($target);
        /** @var $provider ProviderInterface */
        $instance = $provider->get();
        if ($this->logger) {
            $dependencyProvider = new DependencyProvider($provider, $instance);
            $definition = new BoundDefinition;
            $definition->class = $target;
            $this->logger->log($definition, [], [], $dependencyProvider, new Bind);
        }

        return $instance;
    }

    /**
     * @param array  $param
     * @param string $key
     * @param string $typeHint
     * @param string $annotate
     *
     * @return Exception\NotBound
     * @throws Exception\OptionalInjectionNotBound
     */
    private function getNotBoundException(array $param, $key, $typeHint, $annotate)
    {
        if ($param[Definition::OPTIONAL] === true) {
            throw new Exception\OptionalInjectionNotBound($key);
        }
        $name = $param[Definition::PARAM_NAME];
        $msg = "typehint='{$typeHint}', annotate='{$annotate}' for \${$name} in class '{$this->class}'";
        $e = (new Exception\NotBound($msg))->setModule($this->module);

        return $e;
    }

    /**
     * Return parameter using TO_CONSTRUCTOR
     *
     * 1) If parameter is provided, return. (check)
     * 2) If parameter is NOT provided and TO_CONSTRUCTOR binding is available, return parameter with it
     * 3) No binding found, throw exception.
     *
     * @param string         $class
     * @param array          $params
     * @param AbstractModule $module
     *
     * @return array
     * @throws Exception\NotBound
     */
    public function bindConstructor($class, array $params, AbstractModule $module)
    {
        $ref = method_exists($class, '__construct') ? new \ReflectionMethod($class, '__construct') : false;
        if ($ref === false) {
            return $params;
        }
        $parameters = $ref->getParameters();
        foreach ($parameters as $index => $parameter) {
            /* @var $parameter \ReflectionParameter */
            $params = $this->constructParams($params, $index, $parameter, $module, $class);
        }

        return $params;
    }

    /**
     * @param array                $params
     * @param int                  $index
     * @param \ReflectionParameter $parameter
     * @param AbstractModule       $module
     * @param string               $class
     *
     * @return array
     * @throws Exception\NotBound
     */
    private function constructParams(array $params, $index, \ReflectionParameter $parameter, AbstractModule $module, $class)
    {
        // has binding ?
        $params = array_values($params);
        if (isset($params[$index])) {
            return $params;
        }
        if ($this->hasConstructorBinding($module, $class)) {
            $params[$index] = $module[$class]['*'][AbstractModule::TO][1][$parameter->name];

            return $params;
        }
        $param = $this->getNoBoundConstructorParam($parameter, $params, $index, $class);

        return $param;
    }

    /**
     * @param \ReflectionParameter $parameter
     * @param array                $params
     * @param string               $index
     * @param string               $class
     *
     * @return array
     * @throws Exception\NotBound
     */
    private function getNoBoundConstructorParam(\ReflectionParameter $parameter, array $params, $index, $class)
    {
        // has constructor default value ?
        if ($parameter->isDefaultValueAvailable() === true) {
            return $params;
        }
        // is typehint class ?
        $classRef = $parameter->getClass();
        if ($classRef && !$classRef->isInterface()) {
            $params[$index] = $this->injector->getInstance($classRef->name);

            return $params;
        }
        $msg = is_null($classRef) ? "Valid interface is not found. (array ?)" : "Interface [{$classRef->name}] is not bound.";
        $msg .= " Injection requested at argument #{$index} \${$parameter->name} in {$class} constructor.";
        throw new Exception\NotBound($msg);
    }

    /**
     * @param AbstractModule $module
     * @param string         $class
     *
     * @return bool
     */
    private function hasConstructorBinding(AbstractModule $module, $class)
    {
        return ($module[$class]['*'][AbstractModule::TO][0] === AbstractModule::TO_CONSTRUCTOR);
    }
}

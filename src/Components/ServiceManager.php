<?php

namespace DreamFactory\Core\Components;

use DreamFactory\Core\Contracts\ServiceInterface;
use DreamFactory\Core\Contracts\ServiceResponseInterface;
use DreamFactory\Core\Contracts\ServiceTypeInterface;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\ServiceRequest;
use DreamFactory\Library\Utility\Enums\Verbs;
use InvalidArgumentException;

class ServiceManager
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The active service instances.
     *
     * @var array
     */
    protected $services = [];

    /**
     * The custom service resolvers.
     *
     * @var array
     */
    protected $extensions = [];

    /**
     * The custom service type information.
     *
     * @var ServiceTypeInterface[]
     */
    protected $types = [];

    /**
     * Create a new service manager instance.
     *
     * @param  \Illuminate\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Get a service instance.
     *
     * @param  string $name
     *
     * @return ServiceInterface
     */
    public function getService($name)
    {
        // If we haven't created this service, we'll create it based on the config provided.
        if (!isset($this->services[$name])) {
            $service = $this->makeService($name);

//            if ($this->app->bound('events')) {
//                $connection->setEventDispatcher($this->app['events']);
//            }

            $this->services[$name] = $service;
        }

        return $this->services[$name];
    }

    public function getServiceById($id)
    {
        $name = Service::getCachedNameById($id);

        return $this->getService($name);
    }

    /**
     * Disconnect from the given service and remove from local cache.
     *
     * @param  string $name
     *
     * @return void
     */
    public function purge($name)
    {
        unset($this->services[$name]);
    }

    /**
     * Make the service instance.
     *
     * @param  string $name
     *
     * @return ServiceInterface
     */
    protected function makeService($name)
    {
        $config = $this->getConfig($name);

        // First we will check by the service name to see if an extension has been
        // registered specifically for that service. If it has we will call the
        // Closure and pass it the config allowing it to resolve the service.
        if (isset($this->extensions[$name])) {
            return call_user_func($this->extensions[$name], $config, $name);
        }

        $config = $this->getDbConfig($name);
        $type = $config['type'];

        // Next we will check to see if a type extension has been registered for a service type
        // and will call the factory Closure if so, which allows us to have a more generic
        // resolver for the service types themselves which applies to all services.
        if (isset($this->types[$type])) {
            return $this->types[$type]->make($name, $config);
        }
        
        throw new InvalidArgumentException("Unsupported service type '$type'.");
    }

    /**
     * Get the configuration for a service.
     *
     * @param  string $name
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    protected function getConfig($name)
    {
        if (empty($name)) {
            throw new InvalidArgumentException("Service 'name' can not be empty.");
        }

        $services = $this->app['config']['df.service'];

        return array_get($services, $name);
    }

    /**
     * Get the configuration for a service.
     *
     * @param  string $name
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    protected function getDbConfig($name)
    {
        if (empty($name)) {
            throw new InvalidArgumentException("Service 'name' can not be empty.");
        }

        $config = Service::getCachedByName($name);

        return $config;
    }

    /**
     * Register a service type extension resolver.
     *
     * @param  ServiceTypeInterface|null $type
     *
     * @return void
     */
    public function addType(ServiceTypeInterface $type)
    {
        $this->types[$type->getName()] = $type;
    }

    /**
     * Return the service type info.
     *
     * @param string $name
     *
     * @return ServiceTypeInterface
     */
    public function getServiceType($name)
    {
        if (isset($this->types[$name])) {
            return $this->types[$name];
        }

        return null;
    }

    /**
     * Return all of the known service types.
     *
     * @return ServiceTypeInterface[]
     */
    public function getServiceTypes()
    {
        return $this->types;
    }

    /**
     * Return all of the created services.
     *
     * @return array
     */
    public function getServices()
    {
        return $this->services;
    }

    /**
     * @param string      $service
     * @param string      $verb
     * @param string|null $resource
     * @param array       $query
     * @param array       $header
     * @param null        $payload
     * @param string|null $format
     *
     * @return \DreamFactory\Core\Contracts\ServiceResponseInterface|mixed
     * @throws BadRequestException
     * @throws \Exception
     */
    public function handleRequest(
        $service,
        $verb = Verbs::GET,
        $resource = null,
        $query = [],
        $header = [],
        $payload = null,
        $format = null
    ){
        $_FILES = []; // reset so that internal calls can handle other files.
        $request = new ServiceRequest();
        $request->setMethod($verb);
        $request->setParameters($query);
        $request->setHeaders($header);
        if (!empty($payload)) {
            if (is_array($payload)) {
                $request->setContent($payload);
            } elseif (empty($format)) {
                throw new BadRequestException('Payload with undeclared format.');
            } else {
                $request->setContent($payload, $format);
            }
        }

        $response = $this->getService($service)->handleRequest($request, $resource);

        if ($response instanceof ServiceResponseInterface) {
            return $response->getContent();
        } else {
            return $response;
        }
    }
}

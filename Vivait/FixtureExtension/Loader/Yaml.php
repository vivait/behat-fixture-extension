<?php

namespace Vivait\FixtureExtension\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Knp\FriendlyContexts\Alice\ProviderResolver;
use Nelmio\Alice\Loader\Yaml as BaseLoader;

class Yaml extends BaseLoader
{
    /**
     * @var array
     */
    private $objectsCache = [];

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var bool
     */
    private $use_cache;

    public function __construct($locale, ProviderResolver $providers, $use_cache = true)
    {
        $this->use_cache = $use_cache;
        parent::__construct($locale, $providers->all());
    }

    public function addObjectsToCache($objects)
    {
//        var_dump('Adding to cache');
//        var_dump(array_keys($objects));
        $this->objectsCache = array_merge($this->objectsCache, $objects);
    }

    public function addTemplatesToCache($templates)
    {
//        var_dump('Adding templates to cache');
//        var_dump(array_keys($templates));
        $this->templates = array_merge($this->templates, $templates);
    }

    public function getCache()
    {
        return $this->objectsCache;
    }

    public function clearCache()
    {
        $this->objectsCache = [];
    }

    /**
     * Gets entityManager
     * @return mixed
     */
    public function getEntityManager()
    {
        return $this->entityManager;
    }

    /**
     * Sets entityManager
     * @param EntityManagerInterface $entityManager
     * @return $this
     */
    public function setEntityManager(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;

        return $this;
    }
//
//    /**
//     * {@inheritDoc}
//     */
//    public function load($dataOrFile)
//    {
//        $this->templates = $this->templatesCache;
//
//        $objects = parent::load($dataOrFile);
//
//        $this->ddObjectsToCache($this->references);
//        $this->ddTemplatesToCache($this->templates);
//
//        return $objects;
//    }

    /**
     * {@inheritDoc}
     */
    public function getReference($name, $property = null)
    {
//        var_dump('Looking for:');
//        var_dump($name);
        if (isset($this->objectsCache[$name])) {
            try {
                $reference = $this->entityManager->merge($this->objectsCache[$name]);
            }
            catch (\Exception $e) {
                var_dump($e->getMessage());
                var_dump($this->objectsCache[$name]);
                exit;
            }
        }
        else if (isset($this->references[$name])) {
            $reference = $this->references[$name];
        }
        else {
            throw new \UnexpectedValueException('Reference '.$name.' is not defined');
        }

        if ($property !== null) {
            if (property_exists($reference, $property)) {
                $prop = new \ReflectionProperty($reference, $property);

                if ($prop->isPublic()) {
                    return $reference->{$property};
                }
            }

            $getter = 'get'.ucfirst($property);
            if (method_exists($reference, $getter) && is_callable(array($reference, $getter))) {
                return $reference->$getter();
            }

            throw new \UnexpectedValueException('Property '.$property.' is not defined for reference '.$name);
        }

        return $reference;
    }

    public function getTemplates() {
        return $this->templates;
    }
}

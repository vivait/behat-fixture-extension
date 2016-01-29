<?php

namespace Vivait\FixtureExtension\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Knp\FriendlyContexts\Alice\ProviderResolver;
use Nelmio\Alice\Fixtures\Loader as BaseLoader;
use Symfony\Component\Yaml\Yaml as YamlParser;

class Yaml extends BaseLoader
{
    /**
     * @var array
     */
    private $processedFiles = [];

    /**
     * @var array
     */
    private $objectsCache = [];

    public function __construct($locale, ProviderResolver $providers)
    {
        parent::__construct($locale, $providers->all());
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
     * {@inheritdoc}
     */
    protected function instantiateFixtures(array $fixtures)
    {
        parent::instantiateFixtures($fixtures);

        foreach ($fixtures as $fixture) {
            $spec = array_map(function ($property) {
                return $property->getValue();
            }, $fixture->getProperties());

            $this->objectsCache[] = [ $spec, $this->objects->get($fixture->getName()) ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function load($file)
    {
        $data = $this->parse($file);
        return parent::load($data);
    }

    /**
     * @param string $file
     * @return array
     * @throws \UnexpectedValueException
     */
    public function parse($file)
    {
        // We've already processed this file
        if (isset($this->processedFiles[$file])) {
            return [];
        }

        ob_start();
        $loader = $this;

        if (!file_exists($file)) {
            throw new \InvalidArgumentException('The file could not be found: '.$file);
        }

        // isolates the file from current context variables and gives
        // it access to the $loader object to inline php blocks if needed
        $includeWrapper = function () use ($file, $loader) {
            return require $file;
        };
        $data = $includeWrapper();

        if (1 === $data) {
            // include didn't return data but included correctly, parse it as yaml
            $yaml = ob_get_clean();
            $data = YamlParser::parse($yaml);
        } else {
            // make sure to clean up if theres a failure
            ob_end_clean();
        }

        if (!is_array($data)) {
            throw new \UnexpectedValueException('Yaml files must parse to an array of data');
        }

        $this->processedFiles[$file] = true;

        $data = $this->processIncludes($data, $file);

        return $data;
    }

    /**
     * @param array $data
     * @param string $file
     * @return mixed
     */
    private function processIncludes($data, $file)
    {
        if (isset($data['include'])) {
            foreach ($data['include'] as $include) {
                $includeFile = dirname($file) . DIRECTORY_SEPARATOR . $include;
                $includeData = $this->parse($includeFile);
                $data = $this->mergeIncludeData($data, $includeData);
            }
        }

        unset($data['include']);

        return $data;
    }

    /**
     * @param array $data
     * @param array $includeData
     */
    private function mergeIncludeData($data, $includeData)
    {
        foreach ($includeData as $class => $fixtures) {
            if (isset($data[$class])) {
                $data[$class] = array_merge($fixtures, $data[$class]);
            } else {
                $data[$class] = $fixtures;
            }
        }

        return $data;
    }

    public function getTemplates() {
        return $this->templates;
    }
}

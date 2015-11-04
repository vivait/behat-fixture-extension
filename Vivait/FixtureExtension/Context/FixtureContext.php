<?php
namespace Vivait\FixtureExtension\Context;

use Doctrine\ORM\EntityManager;
use Knp\FriendlyContexts\Context\AliceContext;
use Knp\FriendlyContexts\Record\Collection\Bag;
use Vivait\FixtureExtension\Loader\Yaml;
use Vivait\FixtureExtension\Logger\FixtureCacheLogger;

class FixtureContext extends AliceContext
{
    private static $fixtureCache = [];

    /**
     * @param Yaml $loader
     * @param $fixtures
     * @param $files
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function loadFixtures($loader, $fixtures, $files)
    {
//        if (!$this->getParameter('vivait_fixtures.cache_sql')) {
//            parent::loadFixtures($loader, $fixtures, $files);
//            return;
//        }

        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();

        $useCache = $this->getParameter('vivait_fixtures.cache_sql') ? true : false;

//        $loader->setEntityManager($entityManager);

//        $objects = $loader->load($this->filterFixtures($fixtures, $files);

        $objects = $this->loadFixturesInToMemory($loader, $fixtures, $files, $useCache);

        $this->persistEntities($objects);

        if (!$useCache) {
            $this->flushEntities($objects);
            return;
        }

        foreach ($fixtures as $id => $fixture) {
            if (!in_array($id, $files)) {
                continue;
            }

            if (!isset(self::$fixtureCache[$id])) {
                throw new \LogicException(sprintf('Un-warmed up fixture found "%s"', $id));
            }

            $fixtureCache = &self::$fixtureCache[$id];

            if (!empty($fixtureCache['sql'])) {
                $this->runCachedSQL($id);

//                // Reload the caches
//                $loader->addObjectsToCache(self::$fixtureCache[$id]['objects']);
//                $loader->addTemplatesToCache(self::$fixtureCache[$id]['templates']);
            } else {
                $fixtureCache['sql'] = $this->persistEntitiesAndCacheSql($fixtureCache['objects']);
            }
        }

        $entityManager->clear();
    }

    /**
     * @param Yaml $loader
     */
    protected function registerCache($loader)
    {
        if (!$this->getParameter('vivait_fixtures.cache_sql')) {
            parent::registerCache($loader);
            return;
        }

        foreach ($loader->getCache() as $entity) {
            $reflection = new \ReflectionObject($entity);

            /** @var Bag $recordBag */
            $recordBag = $this
                ->getRecordBag();

            do {
                $recordBag
                    ->getCollection($reflection->getName())
                    ->attach($entity)
                ;
                $reflection = $reflection->getParentClass();
            } while (false !== $reflection);
        }
    }

    /**
     * @param $id
     * @param $connection
     */
    protected function runCachedSQL($id)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();
        $connection = $entityManager
            ->getConnection();

        foreach (self::$fixtureCache[$id]['sql'] as $cache) {
            $connection->prepare($cache['sql'])
                       ->execute($cache['params']);
        }
    }

    /**
     * @param $objects
     */
    protected function persistEntities($objects)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();

        foreach ($objects as $entity) {
            $entityManager->persist($entity);
        }
    }

    /**
     * @param $objects
     */
    protected function flushEntities($objects)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();

        $entityManager->flush($objects);
    }

    /**
     * @param $fixtures
     * @param $files
     * @return array
     */
    protected function filterFixtures($fixtures, $files)
    {
        $fixtureFiles = [];

        // Warm the object caches
        foreach ($fixtures as $id => $fixture) {
            if (!in_array($id, $files)) {
                continue;
            }

            $fixtureFiles[] = $fixtures;
        }

        return $fixtureFiles;
    }

    /**
     * @param $loader
     * @param $fixtures
     * @param $files
     * @param $useCache
     * @return array
     */
    protected function loadFixturesInToMemory($loader, $fixtures, $files, $useCache)
    {
        $objects = [];

        // Warm the object caches
        foreach ($fixtures as $id => $fixture) {
            if (!in_array($id, $files)) {
                continue;
            }

            if (isset(self::$fixtureCache[$id]) && $useCache) {
                $objects = array_merge($objects, self::$fixtureCache[$id]['objects']);
            } else {
                $fixtureObjects = $loader->load($fixture);
                $objects = array_merge($objects, $fixtureObjects);

                self::$fixtureCache[$id]['objects'] = $fixtureObjects;
            }
        }

        return $objects;
    }

    protected function persistEntitiesAndCacheSql($objects)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();
        $connection = $entityManager
            ->getConnection();
        $configuration = $connection
            ->getConfiguration();

        $logger = new FixtureCacheLogger(
            $connection->getDatabasePlatform(),
            array(
                FixtureCacheLogger::QUERY_TYPE_UPDATE,
                FixtureCacheLogger::QUERY_TYPE_INSERT,
                FixtureCacheLogger::QUERY_TYPE_DELETE
            )
        );

        $oldLogger = $configuration->getSQLLogger();

        try {
            // Start the caching logger
            $configuration->setSQLLogger($logger);

            $this->flushEntities($objects);

            return $logger->queries;
        } finally {
            $configuration->setSQLLogger($oldLogger);
        }
    }
}

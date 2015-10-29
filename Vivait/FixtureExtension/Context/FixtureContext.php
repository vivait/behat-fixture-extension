<?php
namespace Vivait\FixtureExtension\Context;
use Doctrine\Common\Cache\ClearableCache;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Knp\FriendlyContexts\Context\AliceContext;
use Knp\FriendlyContexts\Record\Collection\Bag;
use Symfony\Bridge\Doctrine\RegistryInterface;
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
        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();
        $connection = $entityManager
            ->getConnection();
        $configuration = $connection
            ->getConfiguration();

        $useCache = $this->getParameter('vivait_fixtures.cache_sql') ? true : false;

        $loader->setEntityManager($entityManager);

        foreach ($fixtures as $id => $fixture) {
            if (!in_array($id, $files)) {
                continue;
            }

            if (!$useCache) {
                $this->loadFixture($loader, $fixture);

                $loader->addObjectsToCache(self::$fixtureCache[$id]['objects']);
            }
            else if (isset(self::$fixtureCache[$id])) {
                // Reload the caches
                $loader->addObjectsToCache(self::$fixtureCache[$id]['objects']);
                $loader->addTemplatesToCache(self::$fixtureCache[$id]['templates']);

                $this->runCachedSQL($id);
            } else {
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

                    $objects = $this->loadFixture($loader, $fixture);

                    // Store a cache of the fixture
                    self::$fixtureCache[$id] = [
                        'objects' => $objects,
                        'templates' => $loader->getTemplates(),
                        'sql' => $logger->queries
                    ];
                } finally {
                    $configuration->setSQLLogger($oldLogger);
                }
            }
        }

        $entityManager->clear();
    }

    /**
     * @param Yaml $loader
     */
    protected function registerCache($loader)
    {
        $useCache = $this->getParameter('vivait_fixtures.cache_sql') ? true : false;

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

        if (!$useCache) {
            $loader->clearCache();
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
     * @param Yaml $loader
     * @param string $fixture
     * @return array
     */
    protected function loadFixture($loader, $fixture)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();

        // Load the fixture and merge it with the cache
        $objects = $loader->load($fixture);

        foreach ($objects as $key => $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();

        return $objects;
    }
}

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

class FixturesContext extends AliceContext
{
    private $purger;
    private $useCache = true;
    private static $hasSchema = false;
    private static $fixtureCache = [];

    function __construct(ORMPurger $purger = null)
    {
        $this->purger = $purger ?: new ORMPurger();
    }

    /**
     * @BeforeScenario
     */
    public function beforeScenario($event)
    {
        $this->storeTags($event);

        if ($this->hasTags(['reset-em', '~not-reset-em'])) {
            /** @var RegistryInterface $doctrine */
            $doctrine = $this->get('doctrine');
            $emTags = $this->getTagContent('reset-em') ?: ['default'];

            foreach ($emTags as $entityManager) {
                /** @var EntityManager $entityManager */
                $entityManager = $doctrine->getManager($entityManager);
//                var_dump(spl_object_hash($entityManager));

                $this->preparePurger($entityManager);

//                $entityManager->transactional(function() {
                    $this->purger->purge();
//                });

                $this->resetPurger($entityManager);
            }
        }
    }

    protected function getMetadata(EntityManager $entityManager)
    {
        return $entityManager->getMetadataFactory()->getAllMetadata();
    }

    /**
     * Enable/disable foreign key checks on the MySQL platform
     *
     * @param EntityManager $entityManager
     * @param bool $bool
     * @throws \Doctrine\DBAL\DBALException
     */
    private function setForeignKeyChecks(EntityManager $entityManager, $bool)
    {
        $connection = $entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof MySqlPlatform) {
            $connection->query(sprintf('SET FOREIGN_KEY_CHECKS=%s', (int) $bool));
        }
    }

    /**
     * @param $entityManager
     * @return array
     */
    private function preparePurger(EntityManager $entityManager)
    {
        $this->setForeignKeyChecks($entityManager, false);

        if (!self::$hasSchema) {
            $tool = new SchemaTool($entityManager);
            $metadata = $this->getMetadata($entityManager);
            $tool->updateSchema($metadata, true);
            self::$hasSchema = true;
        }

        $this->purger->setEntityManager($entityManager);
        $this->purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);

        return $this->purger;
    }

    private function resetPurger(EntityManager $entityManager) {
        $this->setForeignKeyChecks($entityManager, true);

        /** @var ClearableCache $cacheDriver */
        $cacheDriver = $entityManager->getConfiguration()->getResultCacheImpl();

        if ($cacheDriver) {
            $cacheDriver->deleteAll();
        }
    }

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
            }
            else if (isset(self::$fixtureCache[$id])) {
                // Reload the caches
                $loader->addObjectsToCache(self::$fixtureCache[$id]['objects']);
                $loader->addTemplatesToCache(self::$fixtureCache[$id]['templates']);

                $this->runCachedSQL($id);
            } else {
//                var_dump('Cache miss for: ' . $id);
//                var_dump(array_keys(self::$fixtureCache));

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
//                    var_dump('Resetting logger');
                    $configuration->setSQLLogger($oldLogger);
                }
            }
        }
//        var_dump(spl_object_hash($entityManager));

//        var_dump($configuration->getResultCacheImpl());exit;
//        ->getManager()->getConfiguration()->getResultCacheImpl()->delete('YOURKEY')
        $entityManager->clear();
    }

    /**
     * @param Yaml $loader
     */
    protected function registerCache($loader)
    {
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

//                var_dump('Using cache');
//                    $entityManager->transactional(function() use ($id, $connection) {
//                    var_dump($id);
        try {
            foreach (self::$fixtureCache[$id]['sql'] as $cache) {
                $connection->prepare($cache['sql'])
                           ->execute($cache['params']);
            }
        } catch (\Exception $e) {
            var_dump($cache['sql']);
            var_dump($cache['params']);

            throw $e;
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

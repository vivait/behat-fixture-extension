<?php

namespace Vivait\FixtureExtension\Purger;

use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\Common\DataFixtures\Purger\PurgerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Vivait\FixtureExtension\Logger\FixtureCacheLogger;

class CachedPurger implements PurgerInterface
{
    private static $cachedSQL;

    /**
     * @var ORMPurger
     */
    private $purger;

    /**
     * @param ORMPurger $purger
     */
    public function __construct(ORMPurger $purger)
    {
        $this->purger = $purger;
    }

    /**
     * Set the purge mode
     *
     * @param $mode
     * @return void
     */
    public function setPurgeMode($mode)
    {
        $this->purger->setPurgeMode($mode);
    }

    /**
     * Set the EntityManagerInterface instance this purger instance should use.
     *
     * @param EntityManagerInterface $em
     */
    public function setEntityManager(EntityManagerInterface $em)
    {
        $this->purger->setEntityManager($em);
    }

    /**
     * Get the purge mode
     *
     * @return int
     */
    public function getPurgeMode()
    {
        return $this->purger->getPurgeMode();
    }

    /**
     * Retrieve the EntityManagerInterface instance this purger instance is using.
     *
     * @return \Doctrine\ORM\EntityManagerInterface
     */
    public function getObjectManager()
    {
        return $this->purger->getObjectManager();
    }


    /**
     * Purge the data from the database for the given EntityManager.
     *
     * @return void
     */
    function purge()
    {
        $em = $this->getObjectManager();
        $connection = $em->getConnection();
        $hash = spl_object_hash($em);

        if (!isset(self::$cachedSQL[$hash])) {
            $configuration = $connection->getConfiguration();

            $logger = new FixtureCacheLogger($connection->getDatabasePlatform());


            $oldLogger = $configuration->getSQLLogger();
            $configuration->setSQLLogger($logger);

            $this->purger->purge();

            $configuration->setSQLLogger($oldLogger);

            self::$cachedSQL[$hash] = $logger->queries;
        }
        else {
            foreach (self::$cachedSQL[$hash] as $cache) {
                $connection->executeUpdate($cache['sql'], $cache['params'], $cache['types']);
            }
        }
    }
}

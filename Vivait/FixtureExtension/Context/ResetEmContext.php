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

class ResetEmContext extends AliceContext
{
    private $purger;
    private static $hasSchema = false;

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

                $this->preparePurger($entityManager);

                $this->purger->purge();

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
}

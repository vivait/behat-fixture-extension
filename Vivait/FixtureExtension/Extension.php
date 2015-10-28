<?php

namespace Vivait\FixtureExtension;

use Behat\Testwork\ServiceContainer\Extension as ExtensionInterface;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Knp\FriendlyContexts\DependencyInjection\Compiler;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class Extension implements ExtensionInterface
{
    public function initialize(ExtensionManager $extensionManager)
    {
    }

    public function load(ContainerBuilder $container, array $config)
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/services'));
        $loader->load('core.yml');

        $container->setParameter('vivait_fixtures.cache_sql', $config['cache_sql']);
    }

    public function configure(ArrayNodeDefinition $builder)
    {
        $builder
            ->children()
                ->booleanNode('cache_sql')
                    ->defaultTrue()
                ->end()
            ->end()
        ;
    }

    public function process(ContainerBuilder $container)
    {
    }

    public function getConfigKey()
    {
        return 'vivait_fixtures';
    }
}

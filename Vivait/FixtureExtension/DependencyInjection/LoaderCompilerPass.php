<?php
namespace Vivait\FixtureExtension\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class LoaderCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('friendly.alice.fixtures.loader');

        $definition->setClass('Vivait\FixtureExtension\Loader\Yaml');
    }
}

<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class DoublesparkContaoAtlasExtension extends Extension
{
    public function getAlias(): string
    {
        return 'doublespark_contao_atlas';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter('doublespark_contao_atlas.token_hash', $config['token_hash']);
        $container->setParameter('doublespark_contao_atlas.timezone', $config['timezone']);
        $container->setParameter('doublespark_contao_atlas.default_author_id', $config['default_author_id']);
        $container->setParameter('doublespark_contao_atlas.category_mode', $config['category_mode']);
        $container->setParameter('doublespark_contao_atlas.category_column', $config['category_column']);

        (new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config')))->load('services.yaml');
    }
}

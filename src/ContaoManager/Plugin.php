<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Config\ConfigPluginInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Doublespark\ContaoAtlasBundle\DoublesparkContaoAtlasBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Registers the bundle, its routes, and the site's own token file (config/atlas_api.yaml, written by atlas:mint-token).
 */
class Plugin implements BundlePluginInterface, RoutingPluginInterface, ConfigPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [BundleConfig::create(DoublesparkContaoAtlasBundle::class)->setLoadAfter([ContaoCoreBundle::class])];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel)
    {
        return $resolver
            ->resolve(__DIR__.'/../Resources/config/routes.yaml')
            ->load(__DIR__.'/../Resources/config/routes.yaml')
        ;
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader, array $managerConfig): void
    {
        $loader->load(static function (\Symfony\Component\DependencyInjection\ContainerBuilder $container) use ($loader): void {
            $file = $container->getParameter('kernel.project_dir').'/config/atlas_api.yaml';

            if (is_file($file)) {
                $loader->load($file);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('doublespark_contao_atlas');
        $tree->getRootNode()
            ->children()
                // sha256 of the bearer token; the token itself is never stored on the site. Empty means every request is refused.
                ->scalarNode('token_hash')->defaultValue('')->end()
                ->scalarNode('timezone')->defaultValue('Europe/London')->end()
                // tl_user id written as the author of a new entry that arrives without author_id. 0 means none. Set it in the site's own
                // config/config.yaml, NOT in config/atlas_api.yaml, which contao-set-token rewrites. Atlas normally sends author_id itself.
                ->integerNode('default_author_id')->defaultValue(0)->min(0)->end()
                // How category_ids is stored. '' (the default) and 'extension': the news categories extension's data model, found by looking at the schema.
                // 'column': the category is ONE whole number in one tl_news column (category_column), for a site with its own category dropdown
                // (Doublespark: primary_category). Written to config/atlas_api.yaml (gitignored) by Atlas, beside token_hash.
                ->scalarNode('category_mode')->defaultValue('')->end()
                ->scalarNode('category_column')->defaultValue('')->end()
            ->end()
        ;

        return $tree;
    }
}

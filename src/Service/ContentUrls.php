<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsModel;
use Contao\PageModel;

/**
 * The one place the bundle works out the public address of a news entry or a page. Contao 5.3 and later have contao.routing.content_url_generator;
 * earlier versions (4.13 to 5.2) use News::generateNewsUrl and PageModel::getAbsoluteUrl. A URL Contao cannot work out is null, never an error:
 * every caller has already done its real work by the time it asks.
 */
class ContentUrls
{
    public function __construct(private readonly ContaoFramework $framework, private readonly ?object $contentUrlGenerator)
    {
    }

    public function news(int $id): ?string
    {
        try {
            $this->framework->initialize();
            $model = NewsModel::findByPk($id);
            if (null === $model) {
                return null;
            }
            if (null !== $this->contentUrlGenerator) { // Contao 5.3 and later
                return $this->contentUrlGenerator->generate($model, [], 1); // 1 = UrlGeneratorInterface::ABSOLUTE_URL
            }
            if (method_exists(\Contao\News::class, 'generateNewsUrl')) { // Contao 4.13 to 5.2
                return \Contao\News::generateNewsUrl($model, false, true);
            }
        } catch (\Throwable) {
            // Fall through to null.
        }

        return null;
    }

    public function page(int $id): ?string
    {
        try {
            $this->framework->initialize();
            $model = PageModel::findByPk($id);
            if (null === $model) {
                return null;
            }
            if (null !== $this->contentUrlGenerator) { // Contao 5.3 and later
                return $this->contentUrlGenerator->generate($model, [], 1);
            }
            if (method_exists($model, 'getAbsoluteUrl')) { // Contao 4.13 to 5.2
                return $model->getAbsoluteUrl();
            }
        } catch (\Throwable) {
            // Fall through to null.
        }

        return null;
    }
}

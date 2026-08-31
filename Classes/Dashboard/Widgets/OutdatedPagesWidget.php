<?php

/*
 * This file is part of the TYPO3 CMS extension "bw_outdated_pages".
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

declare(strict_types=1);

namespace Blueways\BwOutdatedPages\Dashboard\Widgets;

use Blueways\BwOutdatedPages\Dashboard\OutdatedPagesListDataProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\AdditionalJavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\ButtonProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * Dashboard widget for outdated pages. Does not extend ListWidget because
 * ke_search ships its own Widget/ListWidget.html template override that
 * conflicts with ours — using a unique template name avoids the collision.
 */
class OutdatedPagesWidget implements WidgetInterface, AdditionalJavaScriptInterface, RequestAwareWidgetInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly OutdatedPagesListDataProvider $dataProvider,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly ?ButtonProviderInterface $buttonProvider = null,
        private readonly array $options = [],
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $extensionKeys = array_merge(
            ['typo3/cms-dashboard', 'blueways/bw-outdated-pages'],
            (array)($this->options['additionalExtensionKeys'] ?? [])
        );
        $view = $this->backendViewFactory->create($this->request, $extensionKeys);
        $view->assignMultiple([
            'items' => $this->dataProvider->getItems(),
            'options' => $this->options,
            'button' => $this->buttonProvider,
            'configuration' => $this->configuration,
        ]);
        return $view->render('Widget/OutdatedPagesList');
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getJsFiles(): array
    {
        return [
            'EXT:bw_outdated_pages/Resources/Public/JavaScript/mark-reviewed.js',
        ];
    }
}

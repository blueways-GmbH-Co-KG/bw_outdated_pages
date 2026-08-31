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

namespace Blueways\BwOutdatedPages\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Catches TYPO3's "no page access" exception (1289917924) thrown by
 * BackendModuleValidator when the global page-tree selection points to a page
 * outside the editor's web-mounts. Redirects to the module with id=0 so the
 * editor sees the "please select a page" screen instead of an error.
 */
class ModulePageAccessFallback implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 1289917924) {
                throw $e;
            }

            if (!str_contains($request->getUri()->getPath(), '/module/web/bw-outdated-pages')) {
                throw $e;
            }

            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $uri = (string)$uriBuilder->buildUriFromRoute('web_bwoutdatedpages');

            return new RedirectResponse($uri, 302);
        }
    }
}

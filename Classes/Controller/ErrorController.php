<?php

declare(strict_types=1);
namespace TYPO3\CMS\Frontend\Controller;

/*
 * This file is part of the TYPO3 CMS project.
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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Controller\ErrorPageController;
use TYPO3\CMS\Core\Error\Http\PageNotFoundException;
use TYPO3\CMS\Core\Error\Http\ServiceUnavailableException;
use TYPO3\CMS\Core\Error\PageErrorHandler\PageErrorHandlerInterface;
use TYPO3\CMS\Core\Error\PageErrorHandler\PageErrorHandlerNotConfiguredException;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handles "Page Not Found" or "Page Unavailable" requests,
 * returns a response object.
 */
class ErrorController
{
    /**
     * Used for creating a 500 response ("Page unavailable"), usually due some misconfiguration
     * but if configured, a RedirectResponse could be returned as well.
     *
     * @param ServerRequestInterface $request
     * @param string $message
     * @param array $reasons
     * @return ResponseInterface
     * @throws ServiceUnavailableException
     */
    public function unavailableAction(ServerRequestInterface $request, string $message, array $reasons = []): ResponseInterface
    {
        if (!$this->isPageUnavailableHandlerConfigured()) {
            throw new ServiceUnavailableException($message, 1518472181);
        }
        $errorHandler = $this->getErrorHandlerFromSite($request, 500);
        if ($errorHandler instanceof PageErrorHandlerInterface) {
            return $errorHandler->handlePageError($request, $message, $reasons);
        }
        return $this->handleDefaultError($request, 500, $message ?: 'Page is unavailable');
    }

    /**
     * Used for creating a 404 response ("Page Not Found"),
     * but if configured, a RedirectResponse could be returned as well.
     *
     * @param ServerRequestInterface $request
     * @param string $message
     * @param array $reasons
     * @return ResponseInterface
     * @throws PageNotFoundException
     */
    public function pageNotFoundAction(ServerRequestInterface $request, string $message, array $reasons = []): ResponseInterface
    {
        $errorHandler = $this->getErrorHandlerFromSite($request, 404);
        if ($errorHandler instanceof PageErrorHandlerInterface) {
            return $errorHandler->handlePageError($request, $message, $reasons);
        }
        try {
            return $this->handleDefaultError($request, 404, $message);
        } catch (\RuntimeException $e) {
            throw new PageNotFoundException($message, 1518472189);
        }
    }

    /**
     * Used for creating a 403 response ("Access denied"),
     * but if configured, a RedirectResponse could be returned as well.
     *
     * @param ServerRequestInterface $request
     * @param string $message
     * @param array $reasons
     * @return ResponseInterface
     * @throws PageNotFoundException
     */
    public function accessDeniedAction(ServerRequestInterface $request, string $message, array $reasons = []): ResponseInterface
    {
        $errorHandler = $this->getErrorHandlerFromSite($request, 403);
        if ($errorHandler instanceof PageErrorHandlerInterface) {
            return $errorHandler->handlePageError($request, $message, $reasons);
        }
        try {
            return $this->handleDefaultError($request, 403, $message);
        } catch (\RuntimeException $e) {
            throw new PageNotFoundException($message, 1518472195);
        }
    }

    /**
     * Checks whether the pageUnavailableHandler should be used. To be used, pageUnavailable_handling must be set
     * and devIPMask must not match the current visitor's IP address.
     *
     * @return bool TRUE/FALSE whether the pageUnavailable_handler should be used.
     */
    protected function isPageUnavailableHandlerConfigured(): bool
    {
        return !GeneralUtility::cmpIP(GeneralUtility::getIndpEnv('REMOTE_ADDR'), $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask']);
    }

    /**
     * Checks if a site is configured, and an error handler is configured for this specific status code.
     *
     * @param ServerRequestInterface $request
     * @param int $statusCode
     * @return PageErrorHandlerInterface|null
     */
    protected function getErrorHandlerFromSite(ServerRequestInterface $request, int $statusCode): ?PageErrorHandlerInterface
    {
        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            try {
                return $site->getErrorHandler($statusCode);
            } catch (PageErrorHandlerNotConfiguredException $e) {
                // No error handler found, so fallback back to the generic TYPO3 error handler.
            }
        }
        return null;
    }

    /**
     * Ensures that a response object is created as a "fallback" when no error handler is configured.
     *
     * @param ServerRequestInterface $request
     * @param int $statusCode
     * @param string $reason
     * @return ResponseInterface
     */
    protected function handleDefaultError(ServerRequestInterface $request, int $statusCode, string $reason = ''): ResponseInterface
    {
        if (strpos($request->getHeaderLine('Accept'), 'application/json') !== false) {
            return new JsonResponse(['reason' => $reason], $statusCode);
        }
        $content = GeneralUtility::makeInstance(ErrorPageController::class)->errorAction(
            'Page Not Found',
            'The page did not exist or was inaccessible.' . ($reason ? ' Reason: ' . $reason : '')
        );
        return new HtmlResponse($content, $statusCode);
    }
}

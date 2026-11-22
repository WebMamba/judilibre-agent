<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pages send a visitor to the login form. The agent stream and Live Components are
 * called by fetch(): a redirect to an HTML page would break them, so they get a 401.
 */
final readonly class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_starts_with($request->getPathInfo(), '/agent/') || str_starts_with($request->getPathInfo(), '/_components/')) {
            return new JsonResponse(['message' => $this->translator->trans('research.session_expired')], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($this->urls->generate('login'));
    }
}

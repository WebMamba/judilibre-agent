<?php

declare(strict_types=1);

namespace App\Controller;

use AgUi\Bridge\SymfonyAi\AgUi;
use AgUi\Bridge\SymfonyAi\Exception\InvalidStateException;
use AgUi\Bundle\Http\AgUiResponse;
use AgUi\Run\RunAgentInput;
use App\Dossier\DossierRuns;
use App\Entity\Dossier;
use App\Repository\DossierRepository;
use App\Research\ResearchState;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The AG-UI endpoint: a RunAgentInput in, the researcher's event stream out. The thread
 * is the dossier: born with its first message, owned by its user, run once at a time,
 * and saved after every run (ADR 0002). The model and its options are in
 * config/packages/ai.yaml.
 */
final class StreamController extends AbstractController
{
    #[Route('/agent/stream', name: 'agent_stream', methods: ['POST'])]
    public function __invoke(
        Request $request,
        RunAgentInput $input,
        AgentInterface $researcher,
        AgUi $agUi,
        ResearchState $state,
        DossierRepository $dossiers,
        DossierRuns $runs,
    ): AgUiResponse {
        $owner = $this->getUser()?->getUserIdentifier() ?? throw new AccessDeniedHttpException();
        if (1 !== preg_match('/^'.Dossier::ID_PATTERN.'$/', $input->threadId)) {
            throw new UnprocessableEntityHttpException('The thread id is not a dossier id.');
        }

        $dossier = $dossiers->find($input->threadId) ?? new Dossier($input->threadId, $owner);
        if ($dossier->getOwner() !== $owner) {
            throw new AccessDeniedHttpException('This dossier is not yours.');
        }

        // A run streams for minutes: release the session lock first, or the Live
        // Components of the page would wait for the run to end.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        try {
            $events = $agUi->run($runs->prepare($dossier, $input), $researcher, state: $state);
        } catch (InvalidStateException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new AgUiResponse($runs->record($dossier, $events, $state));
    }
}

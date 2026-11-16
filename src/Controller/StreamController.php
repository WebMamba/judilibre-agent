<?php

declare(strict_types=1);

namespace App\Controller;

use AgUi\Bridge\SymfonyAi\AgUi;
use AgUi\Bridge\SymfonyAi\Exception\InvalidStateException;
use AgUi\Bundle\Http\AgUiResponse;
use AgUi\Run\RunAgentInput;
use App\Research\ResearchState;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The AG-UI endpoint: a RunAgentInput in, the researcher's event stream out, with the
 * research state (filters, approved plan, dossier) as the shared state. The model and
 * its options are in config/packages/ai.yaml.
 */
final readonly class StreamController
{
    #[Route('/agent/stream', name: 'agent_stream', methods: ['POST'])]
    public function __invoke(RunAgentInput $input, AgentInterface $researcher, AgUi $agUi, ResearchState $state): AgUiResponse
    {
        try {
            return new AgUiResponse($agUi->run($input, $researcher, state: $state));
        } catch (InvalidStateException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }
}

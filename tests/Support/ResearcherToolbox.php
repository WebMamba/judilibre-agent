<?php

declare(strict_types=1);

namespace App\Tests\Support;

use AgUi\Run\RunAgentInput;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;

/**
 * The researcher's toolbox as the model sees it during an AG-UI run: its own tools, plus
 * the agent components, which are only offered while AgUi runs the agent.
 */
trait ResearcherToolbox
{
    private static function researcherToolbox(): ToolboxInterface
    {
        self::getContainer()->get('ag_ui.run_scope')->open(
            RunAgentInput::fromArray(['threadId' => 'thread_1', 'runId' => 'run_1', 'messages' => []]),
            [],
            agent: 'researcher',
        );

        return self::getContainer()->get('ai.toolbox.researcher');
    }
}

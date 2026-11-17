<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\EnvUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ResearchPageTest extends WebTestCase
{
    public function testThePageWiresTheChatThePanelsAndTheAgUiClient(): void
    {
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(EnvUserProvider::class)->loadUserByIdentifier('juriste@example.com'));
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');

        $root = $crawler->filter('[data-controller~="ag-ui"]');
        self::assertCount(1, $root);
        self::assertSame('/agent/stream', $root->attr('data-ag-ui-endpoint-value'));
        self::assertMatchesRegularExpression('/^research agent-chat ag-ui$/', (string) $root->attr('data-controller'), 'research and agent-chat listen before ag-ui connects.');

        self::assertSelectorExists('#filters-panel[data-live-name-value="FiltersPanel"]');
        self::assertSelectorExists('#dossier-panel[data-live-name-value="DossierPanel"]');
        self::assertSelectorExists('form [data-ag-ui-target="input"]');
        self::assertSelectorExists('[data-controller~="ag-ui-activities"]');
        self::assertSelectorTextContains('h1', 'Recherche de jurisprudence');
    }
}

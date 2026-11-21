<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Entity\Dossier;
use App\Repository\DossierRepository;
use App\Research\Consultation;
use App\Research\Phase;
use App\Research\ResearchState;
use App\Research\RetainedDecision;
use App\Security\EnvUserProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * The dossiers as the user meets them: the list, a reopened dossier, and what the user
 * does on a dossier outside a run.
 */
final class DossierPagesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->loginUser(self::getContainer()->get(EnvUserProvider::class)->loadUserByIdentifier('juriste@example.com'));
    }

    public function testTheListShowsOnlyTheUsersDossiersAndLeadsToANewOne(): void
    {
        $this->save($this->delivered('mine_'.bin2hex(random_bytes(4)), 'Bail commercial et clause résolutoire'));
        $this->save(new Dossier('theirs_'.bin2hex(random_bytes(4)), 'autre@example.com'));

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Bail commercial et clause résolutoire');
        self::assertCount(0, $crawler->filter('a[href*="/dossiers/theirs_"]'));

        $this->client->request('GET', '/dossiers/new');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Exposez votre problème juridique.');
    }

    public function testAReopenedDossierShowsItsConversationAndItsConsultation(): void
    {
        $dossier = $this->delivered($id = 'reopen_'.bin2hex(random_bytes(4)), 'Licenciement');
        $dossier->recordMessages([
            ['id' => 'u1', 'role' => 'user', 'content' => 'Mon client a été licencié pendant son arrêt maladie.'],
            ['id' => 'm1', 'role' => 'assistant', 'content' => 'Voici la **consultation**. <script>alert(1)</script>', 'toolCalls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'ask_questions', 'arguments' => '{}']],
                ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'write_section', 'arguments' => '{}']],
            ]],
            ['id' => 't1', 'role' => 'tool', 'toolCallId' => 'c1', 'content' => 'Réponses'],
            ['id' => 't2', 'role' => 'tool', 'toolCallId' => 'c2', 'content' => 'Section « Faits » rédigée.'],
            ['id' => 'act_c1', 'role' => 'activity', 'activityType' => 'twig_component', 'content' => ['html' => '<div data-interrupt>Formulaire</div>', 'interruptId' => 'old']],
        ]);
        $this->save($dossier);

        $this->client->request('GET', '/dossiers/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-agent-chat-target="messages"]', 'Mon client a été licencié');
        self::assertSelectorExists('.assistant-text strong');
        self::assertStringNotContainsString('<script>alert(1)</script>', $this->client->getInternalResponse()->getContent(), 'The model\'s HTML is escaped.');
        self::assertSelectorExists('[data-ag-ui-activity="act_c1"].answered');
        self::assertSelectorTextContains('.tool-call', 'Rédaction — Section « Faits » rédigée.');
        self::assertSelectorTextContains('#consultation-panel', 'Cour de cassation, Chambre sociale, 13 mars 2024');
        self::assertSelectorTextContains('#consultation-panel', 'Annexe — Décisions citées');
        self::assertSelectorTextContains('#consultation-panel', 'ne remplace pas l\'analyse d\'un avocat');
    }

    public function testAnotherUsersDossierCannotBeOpened(): void
    {
        $this->save(new Dossier($id = 'other_'.bin2hex(random_bytes(4)), 'autre@example.com'));

        $this->client->request('GET', '/dossiers/'.$id);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/dossiers/'.$id.'/consultation.docx');
        self::assertResponseStatusCodeSame(403);
    }

    public function testADossierIsRenamed(): void
    {
        $this->save($this->delivered($id = 'rename_'.bin2hex(random_bytes(4)), 'Ancien nom'));
        $crawler = $this->client->request('GET', '/');

        $this->client->submit($crawler->filter('form[action="/dossiers/'.$id.'/rename"]')->form(['title' => 'Nouveau nom']));

        self::assertResponseRedirects('/');
        self::assertSame('Nouveau nom', $this->find($id)->getTitle());
    }

    public function testTheLastRewriteOfASectionIsUndoneAndTheResearchResumes(): void
    {
        $this->save($this->delivered($id = 'undo_'.bin2hex(random_bytes(4)), 'Licenciement'));
        $token = $this->client->request('GET', '/')->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/dossiers/'.$id.'/sections/discussion/undo', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame('Première version.', $this->find($id)->getState()['consultation']['sections'][3]['markdown']);

        $this->client->request('POST', '/dossiers/'.$id.'/sections/discussion/undo', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404, 'Only the last rewrite can be undone.');

        $this->client->request('POST', '/dossiers/'.$id.'/resume-research', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $state = json_decode((string) $this->client->getInternalResponse()->getContent(), true)['state'];
        self::assertSame('selection', $state['phase']);
        self::assertTrue($state['consultation']['stale']);

        $this->client->request('POST', '/dossiers/'.$id.'/sections/faits/undo', ['_token' => 'forged']);
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'A forged token changes nothing.');
    }

    public function testAConsultationIsExportedToWord(): void
    {
        $this->save($this->delivered($id = 'docx_'.bin2hex(random_bytes(4)), 'Licenciement'));

        $this->client->request('GET', '/dossiers/'.$id.'/consultation.docx');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-disposition', "attachment; filename=consultation.docx; filename*=utf-8''Consultation%20-%20Licenciement.docx");
        $file = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $xml = (string) $zip->getFromName('word/document.xml');
        unlink($file);
        self::assertStringContainsString('Consultation juridique', $xml);
        self::assertStringContainsString('Cour de cassation, Chambre sociale, 13 mars 2024', $xml);
        self::assertStringContainsString('Annexe', $xml);
    }

    private function delivered(string $id, string $title): Dossier
    {
        $state = new ResearchState();
        $state->phase = Phase::Livre;
        $state->title = $title;
        $state->retained = [new RetainedDecision('65f1c0a2b4e3d2001a7c9e11', 'a1', 'Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457')];
        $state->consultation = new Consultation();
        foreach (array_keys(Consultation::SECTIONS) as $section) {
            $state->consultation->write($section, 'discussion' === $section ? 'Première version.' : 'Texte de la section '.$section.' [[65f1c0a2b4e3d2001a7c9e11]].');
        }
        $state->consultation->write('discussion', 'Seconde version, voir [[65f1c0a2b4e3d2001a7c9e11]].');

        $dossier = new Dossier($id, 'juriste@example.com');
        $dossier->recordState((array) self::getContainer()->get(NormalizerInterface::class)->normalize($state));

        return $dossier;
    }

    private function save(Dossier $dossier): void
    {
        self::getContainer()->get(DossierRepository::class)->save($dossier);
    }

    private function find(string $id): Dossier
    {
        $repository = self::getContainer()->get(DossierRepository::class);
        $repository->getEntityManager()->clear();

        return $repository->find($id) ?? self::fail('No dossier '.$id);
    }
}

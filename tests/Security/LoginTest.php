<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The accounts come from APP_USERS (.env.test: juriste@example.com / secret).
 */
final class LoginTest extends WebTestCase
{
    public function testAVisitorIsSentToTheLoginPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testTheAgentEndpointAnswers401ToAVisitorRatherThanARedirect(): void
    {
        $client = self::createClient();
        $client->request('POST', '/agent/stream', server: ['CONTENT_TYPE' => 'application/json'], content: '{"threadId":"t","runId":"r","messages":[]}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLiveComponentsAnswer401ToAVisitor(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_components/FiltersPanel');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAUserLogsInAndReachesTheResearchPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'juriste@example.com', '_password' => 'secret']);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header', 'juriste@example.com');
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'juriste@example.com', '_password' => 'nope']);
        $client->followRedirect();

        self::assertSelectorExists('[role="alert"]');
        self::assertInputValueSame('_username', 'juriste@example.com');
    }

    public function testAnUnknownUserIsRefused(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'intrus@example.com', '_password' => 'secret']);
        $client->followRedirect();

        self::assertSelectorExists('[role="alert"]');
    }

    public function testAUserLogsOut(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'juriste@example.com', '_password' => 'secret']);
        $client->followRedirect();

        $client->submitForm('Déconnexion');
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }
}

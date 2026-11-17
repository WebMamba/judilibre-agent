<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The accounts, read from APP_USERS (JSON: e-mail => password hash): a handful of users,
 * no database, and no credentials in the repository.
 *
 * @implements UserProviderInterface<InMemoryUser>
 */
final readonly class EnvUserProvider implements UserProviderInterface
{
    /** @var array<string, string> lowercased e-mail => hash */
    private array $users;

    /**
     * @param array<string, string> $users
     */
    public function __construct(
        #[Autowire(env: 'json:APP_USERS')] array $users,
    ) {
        $this->users = array_change_key_case(array_filter($users, 'is_string'));
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $email = strtolower(trim($identifier));
        $hash = $this->users[$email] ?? throw new UserNotFoundException(\sprintf('No account for "%s".', $identifier));

        return new InMemoryUser($email, $hash, ['ROLE_USER']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof InMemoryUser) {
            throw new UnsupportedUserException(\sprintf('Unsupported user "%s".', $user::class));
        }

        // A removed account, or a changed password, ends the session.
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}

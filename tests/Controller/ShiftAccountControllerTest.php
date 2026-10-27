<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use App\Entity\User;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Routed account lifecycle and frontend-only acceptance against an explicitly disposable database. */
#[Group('integration')]
class ShiftAccountControllerTest extends TestCase
{
    /** Creates, edits, disables and password-resets a real account while preserving native security and the standalone shell. */
    public function testIntegratedAccounts(): void
    {
        if (getenv('CALENDAR_SYNC_TEST_DATABASE') !== 'kimai_test') {
            self::markTestSkipped('Requires explicitly selected disposable kimai_test database');
        }
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerInterface::class, $container);
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame('kimai_test', $em->getConnection()->getDatabase());
        $db = $em->getConnection();
        $db->setNestTransactionsWithSavepoints(true);
        $db->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(4));
            $admin = (new User())->setEnabled(true);
            $admin->setUsername('account-admin-' . $suffix);
            $admin->setEmail($admin->getUsername() . '@example.test');
            $admin->setPassword('test-only');
            $admin->setRoles([User::ROLE_SUPER_ADMIN]);
            $em->persist($admin);
            $regular = (new User())->setEnabled(true);
            $regular->setUsername('account-user-' . $suffix);
            $regular->setEmail($regular->getUsername() . '@example.test');
            $regular->setPassword('test-only');
            $regular->setRoles([User::ROLE_USER]);
            $em->persist($regular);
            $em->flush();
            $client = new KernelBrowser($kernel);
            $client->disableReboot();
            $client->loginUser($regular, 'secured_area');
            $client->request('GET', '/en/shifts/account');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertStringNotContainsString('navbar-vertical', $client->getResponse()->getContent() ?: '');
            self::assertStringNotContainsString('/app.', $client->getResponse()->getContent() ?: '');
            $client->request('GET', '/en/shifts/account/users');
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $client->request('POST', '/en/shifts/account/users/new', ['form' => ['username' => 'attack', 'administrator' => '1']]);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $client->request('GET', '/en/profile/' . $regular->getUserIdentifier());
            self::assertSame('/en/shifts/account', $client->getResponse()->headers->get('Location'));
            $client->request('GET', '/en/profile/' . $regular->getUserIdentifier() . '/2fa');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertStringNotContainsString('navbar-vertical', $client->getResponse()->getContent() ?: '');
            $client->loginUser($admin, 'secured_area');
            $ownPassword = $admin->getPassword();
            $crawler = $client->request('GET', '/en/shifts/account/users/' . $admin->getId());
            self::assertCount(1, $crawler->filter('input[name="form[plainPassword][first]"][disabled]'));
            $client->submit($crawler->filter('form')->form(), ['form[plainPassword][first]' => 'Attempted-Bypass2026!', 'form[plainPassword][second]' => 'Attempted-Bypass2026!']);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $admin = $em->find(User::class, $admin->getId());
            self::assertInstanceOf(User::class, $admin);
            self::assertSame($ownPassword, $admin->getPassword());
            self::assertTrue($admin->isSuperAdmin());
            self::assertTrue($admin->isEnabled());
            $client->request('GET', '/en/admin/user/');
            self::assertSame('/en/shifts/account/users', $client->getResponse()->headers->get('Location'));
            $client->request('GET', '/en/admin/customer/');
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $crawler = $client->request('GET', '/en/shifts/account/users/new');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $form = $crawler->filter('form')->form();
            $username = 'created-account-' . $suffix;
            $client->submit($form, ['form[username]' => $username, 'form[email]' => $username . '@example.test', 'form[alias]' => 'Private user', 'form[plainPassword][first]' => 'Test-Only-Password2026!', 'form[plainPassword][second]' => 'Test-Only-Password2026!', 'form[enabled]' => '1']);
            self::assertSame(302, $client->getResponse()->getStatusCode(), substr($client->getResponse()->getContent() ?: '', 0, 500));
            $created = $em->getRepository(User::class)->findOneBy(['username' => $username]);
            self::assertInstanceOf(User::class, $created);
            self::assertTrue($created->isEnabled());
            self::assertFalse($created->isAdmin());
            self::assertSame('hu', $created->getLanguage());
            self::assertSame('Europe/Budapest', $created->getTimezone());
            self::assertNotSame('Test-Only-Password2026!', $created->getPassword());
            $original = $created->getPassword();
            $crawler = $client->request('GET', '/en/shifts/account/users/' . $created->getId());
            $client->submit($crawler->filter('form')->form(), ['form[plainPassword][first]' => '0', 'form[plainPassword][second]' => '0']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $created = $em->find(User::class, $created->getId());
            self::assertInstanceOf(User::class, $created);
            self::assertSame($original, $created->getPassword());
            $crawler = $client->request('GET', '/en/shifts/account/users/' . $created->getId());
            $form = $crawler->filter('form')->form();
            $enabled = $form['form[enabled]'];
            self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $enabled);
            $enabled->untick();
            $client->submit($form);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $created = $em->find(User::class, $created->getId());
            self::assertInstanceOf(User::class, $created);
            self::assertFalse($created->isEnabled());
            self::assertSame($original, $created->getPassword());
            $crawler = $client->request('GET', '/en/shifts/account/users/' . $created->getId());
            $client->submit($crawler->filter('form')->form(), ['form[enabled]' => '1', 'form[reset]' => '1']);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $created = $em->find(User::class, $created->getId());
            self::assertInstanceOf(User::class, $created);
            self::assertTrue($created->requiresPasswordReset());
            $client->loginUser($created, 'secured_area');
            $client->request('GET', '/en/shifts');
            self::assertSame('/en/shifts/account/password', $client->getResponse()->headers->get('Location'));
            $crawler = $client->request('GET', '/en/shifts/account/password');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $form = $crawler->filter('form')->form();
            $client->submit($form, ['user_password[plainPassword][first]' => 'Changed-Password2026!', 'user_password[plainPassword][second]' => 'Changed-Password2026!']);
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $created = $em->find(User::class, $created->getId());
            self::assertInstanceOf(User::class, $created);
            self::assertFalse($created->requiresPasswordReset());
            self::assertNotSame($original, $created->getPassword());
            $client->loginUser($created, 'secured_area');
            $crawler = $client->request('GET', '/en/shifts/account/password');
            self::assertCount(1, $crawler->filter('input[name="user_password[currentPassword]"]'));
            $form = $crawler->filter('form')->form();
            $client->submit($form, ['user_password[currentPassword]' => 'wrong', 'user_password[plainPassword][first]' => 'Another-Password2026!', 'user_password[plainPassword][second]' => 'Another-Password2026!']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
        } finally {
            while ($db->getTransactionNestingLevel() > 0) {
                $db->rollBack();
            }
            $em->clear();
            $kernel->shutdown();
        }
    }
}

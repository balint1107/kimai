<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting;

use App\Entity\ShiftCalendarConnection;
use App\Entity\User;
use App\Kernel;
use App\ShiftAccounting\CalendarSync\CalendarOwnership;
use App\ShiftAccounting\CalendarSync\CalendarSync;
use App\ShiftAccounting\CalendarSync\LocalCalendar;
use App\ShiftAccounting\ShiftService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Exercises two real ordinary users against a disposable database and real routed HTTP requests. */
#[Group('integration')]
class PrivateAccountsTest extends TestCase
{
    /** Private defaults, IDs, reports, calendar claims and native/API boundaries remain isolated; the transaction is always rolled back. */
    public function testTwoPrivateAccounts(): void
    {
        if (getenv('CALENDAR_SYNC_TEST_DATABASE') !== 'kimai_test') {
            self::markTestSkipped('Requires explicitly selected disposable kimai_test database');
        }
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer();
        $registry = $container->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $registry);
        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame('kimai_test', $em->getConnection()->getDatabase());
        $loader = $container->get('console.command_loader');
        self::assertInstanceOf(\Symfony\Component\Console\CommandLoader\CommandLoaderInterface::class, $loader);
        $command = $loader->get('app:shifts:calendar-sync');
        if ($command instanceof LazyCommand) {
            $command = (new \ReflectionMethod($command, 'getCommand'))->invoke($command);
        }
        self::assertInstanceOf(\App\Command\ShiftCalendarCommand::class, $command);
        $sync = (new \ReflectionProperty($command, 'sync'))->getValue($command);
        self::assertInstanceOf(CalendarSync::class, $sync);
        $shifts = (new \ReflectionProperty($sync, 'shifts'))->getValue($sync);
        self::assertInstanceOf(ShiftService::class, $shifts);
        $local = (new \ReflectionProperty($sync, 'local'))->getValue($sync);
        self::assertInstanceOf(LocalCalendar::class, $local);
        $tokens = (new \ReflectionProperty($command, 'tokens'))->getValue($command);
        self::assertInstanceOf(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class, $tokens);
        $previous = $tokens->getToken();
        $db = $em->getConnection();
        $db->setNestTransactionsWithSavepoints(true);
        $db->beginTransaction();
        try {
            $users = [];
            foreach (['alice', 'bob'] as $name) {
                $user = (new User())->setEnabled(true);
                $user->setUsername('private-' . $name . '-' . bin2hex(random_bytes(4)));
                $user->setEmail($user->getUsername() . '@example.test');
                $user->setPassword('test-only-not-a-login');
                $user->setRoles(['ROLE_USER']);
                $user->setTimezone('Europe/Budapest');
                $user->setWizardAsSeen('intro');
                $user->setWizardAsSeen('profile');
                $em->persist($user);
                $users[] = $user;
            }
            $em->flush();
            [$alice, $bob] = $users;
            self::assertSame([], $shifts->workplaces($alice));
            self::assertSame([], $shifts->shifts($alice));
            $a = $shifts->workplace($alice, 'Private shared name', 3500, 'EFO', '#123456');
            $b = $shifts->workplace($bob, 'Private shared name', 9500, 'Számla', '#654321');
            self::assertNotSame($a->getId(), $b->getId());
            self::assertSame(3500.0, $shifts->workplaces($alice)[0]['rate']);
            self::assertSame(9500.0, $shifts->workplaces($bob)[0]['rate']);
            self::assertSame($a, $shifts->workplace($alice, ' private SHARED name ', 3500));
            try {
                $shifts->workplace($alice, 'Stolen defaults', 1, 'EFO', null, $b->getId());
                self::fail('Foreign workplace edit accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame(9500.0, $shifts->workplaces($bob)[0]['rate']);
            }
            $tokens->setToken(new UsernamePasswordToken($alice, 'secured_area', $alice->getRoles()));
            $data = ['workplace' => $a->getId(), 'category' => 'EFO', 'rate' => 3500, 'begin' => '2026-11-03T08:00', 'end' => '2026-11-03T10:00', 'note' => 'private note'];
            $shift = $shifts->save($alice, $data);
            $shifts->workplace($alice, 'Private shared name', 4000, 'EFO', null, $a->getId());
            self::assertSame(3500.0, $shift->getHourlyRate());
            self::assertSame([], $shifts->shifts($bob));
            self::assertCount(1, $shifts->shifts($alice));
            try {
                $shifts->save($alice, array_replace($data, ['workplace' => $b->getId()]));
                self::fail('Foreign workplace shift accepted');
            } catch (\InvalidArgumentException) {
                self::assertCount(1, $shifts->shifts($alice));
            }
            try {
                $shifts->save($bob, array_replace($data, ['workplace' => $b->getId()]), $shift);
                self::fail('Foreign shift edit accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame($alice->getId(), $shift->getUser()?->getId());
            }
            $eventKey = $local->save($alice, ['kind' => 'time_off', 'title' => 'Private holiday', 'all_day' => true, 'begin' => '2026-11-05', 'end' => '2026-11-06', 'note' => '', 'color' => '#123456'], null);
            self::assertArrayNotHasKey($eventKey, $local->records($bob));
            $ca = new ShiftCalendarConnection($alice);
            $cb = new ShiftCalendarConnection($bob);
            $em->persist($ca);
            $em->persist($cb);
            $access = new \App\Entity\AccessToken($bob, 'private-test-token');
            $access->setName('private fixture');
            $em->persist($access);
            $em->flush();
            $ownership = new CalendarOwnership($em);
            $ownership->claim($ca, ['private-fixture-calendar']);
            try {
                $ownership->claim($cb, ['private-fixture-calendar']);
                self::fail('Foreign calendar reservation accepted');
            } catch (\RuntimeException) {
                self::assertFalse($cb->enabled);
            }
            $client = new KernelBrowser($kernel);
            $client->disableReboot();
            $client->loginUser($bob, 'secured_area');
            foreach (['/' => 302, '/en/dashboard/' => 302, '/en/shifts/data' => 200, '/en/admin/customer/' => 403, '/en/admin/user/' => 302, '/api/customers' => 403, '/en/profile/' . $alice->getUserIdentifier() => 403] as $url => $status) {
                $client->request('GET', $url);
                self::assertSame($status, $client->getResponse()->getStatusCode(), $url . ' -> ' . $client->getResponse()->headers->get('Location', 'none'));
                self::assertStringNotContainsString('private note', $client->getResponse()->getContent() ?: '');
            }
            $client->request('GET', '/api/customers', [], [], ['HTTP_AUTHORIZATION' => 'Bearer private-test-token']);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $client->request('GET', '/en/shifts/data');
            /** @var array{events: list<mixed>, holidays: list<mixed>, places: list<array{id:int}>} $response */
            $response = json_decode($client->getResponse()->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $response['events']);
            self::assertSame([], $response['holidays']);
            self::assertCount(1, $response['places']);
            self::assertSame($b->getId(), $response['places'][0]['id']);
            $client->request('GET', '/en/shifts');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $csrf = $client->getCrawler()->filter('#shift-root')->attr('data-token');
            foreach (['/en/shifts/delete' => ['id' => $shift->getId()], '/en/shifts/save' => $data, '/en/shifts/workplace' => ['id' => $a->getId(), 'name' => 'Attack', 'rate' => 1]] as $url => $payload) {
                $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode($payload, JSON_THROW_ON_ERROR));
                self::assertSame(str_ends_with($url, '/delete') ? 404 : 422, $client->getResponse()->getStatusCode(), $url);
            }
            $client->request('GET', '/en/shifts/export/csv');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('Workplace', $client->getInternalResponse()->getContent());
            self::assertStringNotContainsString('private note', $client->getInternalResponse()->getContent());
        } finally {
            while ($db->getTransactionNestingLevel() > 0) {
                $db->rollBack();
            }
            $tokens->setToken($previous);
            $em->clear();
            $kernel->shutdown();
        }
    }
}

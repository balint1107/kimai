<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting;

use App\Entity\ShiftTimeOff;
use App\Entity\User;
use App\ShiftAccounting\TimeOffService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises DST date ranges, strict validation and owner isolation for calendar-only time off. */
class TimeOffServiceTest extends TestCase
{
    /** Confirms inclusive all-day dates span a Budapest DST transition without adding a fixed 24 hours. */
    public function testAllDayDstRange(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');
        $service = new TimeOffService($em);
        $absence = $service->save(new User(), ['all_day' => true, 'begin' => '2026-03-29', 'end' => '2026-03-29', 'note' => ' DST ']);
        self::assertSame(23 * 3600, $absence->end->getTimestamp() - $absence->begin->getTimestamp());
        $event = $service->record($absence);
        self::assertSame('2026-03-29', $event['start']);
        self::assertSame('2026-03-30', $event['end']);
        self::assertSame('DST', $event['title']);
        self::assertTrue($event['allDay']);
        self::assertFalse($event['editable']);
    }

    /** Confirms partial-day times are stored as UTC instants and rendered with the Budapest offset. */
    public function testPartialDay(): void
    {
        $service = new TimeOffService($this->createMock(EntityManagerInterface::class));
        $absence = $service->save(new User(), ['all_day' => false, 'begin' => '2026-10-26T09:15', 'end' => '2026-10-26T12:45']);
        self::assertSame('08:15', $absence->begin->format('H:i'));
        self::assertSame('UTC', $absence->begin->getTimezone()->getName());
        self::assertSame('2026-10-26T12:45:00+01:00', $service->record($absence)['end']);
    }

    /** Persists a titled general event with its own color/identity and no accounting fields. */
    public function testGeneralEvent(): void
    {
        $service = new TimeOffService($this->createMock(EntityManagerInterface::class));
        $event = $service->save(new User(), ['kind' => 'event', 'title' => ' Meeting ', 'color' => '#123abc', 'note' => 'Bring notes', 'begin' => '2026-10-26T09:00', 'end' => '2026-10-26T10:00']);
        $event->id = 42;
        $record = $service->record($event);
        self::assertSame('event-42', $record['id']);
        self::assertSame('event', $record['kind']);
        self::assertSame('Meeting', $record['title']);
        self::assertSame('Bring notes', $record['note']);
        self::assertSame('#123abc', $record['color']);
        self::assertArrayNotHasKey('rate', $record);
        self::assertArrayNotHasKey('amount', $record);
    }

    /** Keeps holiday names, notes and custom colors separate when serializing the calendar event. */
    public function testNamedHolidayColor(): void
    {
        $service = new TimeOffService($this->createMock(EntityManagerInterface::class));
        $holiday = $service->save(new User(), ['kind' => 'time_off', 'title' => ' Family trip ', 'color' => '#e8590c', 'note' => 'Travel', 'all_day' => true, 'begin' => '2026-10-26', 'end' => '2026-10-27']);
        $record = $service->record($holiday);
        self::assertSame('Family trip', $record['title']);
        self::assertSame('Family trip', $record['event_title']);
        self::assertSame('Travel', $record['note']);
        self::assertSame('#e8590c', $record['color']);
    }

    /** Rejects changing a saved holiday into a general event, preserving its existing meaning. */
    public function testSavedTypeIsImmutable(): void
    {
        $owner = new User();
        $holiday = new ShiftTimeOff($owner, new \DateTimeImmutable(), new \DateTimeImmutable(), true);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($holiday);
        $em->expects(self::never())->method('persist');
        $this->expectException(\InvalidArgumentException::class);
        (new TimeOffService($em))->save($owner, ['id' => 1, 'kind' => 'event', 'title' => 'Meeting', 'begin' => '2026-10-26T09:00', 'end' => '2026-10-26T10:00']);
    }

    /** Provides malformed, nonexistent DST and reversed ranges as rejected JSON-shaped input.
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'impossible date' => [['all_day' => true, 'begin' => '2026-02-30', 'end' => '2026-03-01']];
        yield 'reversed whole days' => [['all_day' => true, 'begin' => '2026-03-03', 'end' => '2026-03-01']];
        yield 'nonexistent local hour' => [['begin' => '2026-03-29T02:30', 'end' => '2026-03-29T04:00']];
        yield 'equal partial times' => [['begin' => '2026-10-26T09:00', 'end' => '2026-10-26T09:00']];
        yield 'missing dates' => [[]];
        yield 'invalid color' => [['kind' => 'event', 'title' => 'Meeting', 'color' => 'red', 'begin' => '2026-10-26T09:00', 'end' => '2026-10-26T10:00']];
        yield 'unknown kind' => [['kind' => 'shift', 'begin' => '2026-10-26T09:00', 'end' => '2026-10-26T10:00']];
        yield 'missing event title' => [['kind' => 'event', 'begin' => '2026-10-26T09:00', 'end' => '2026-10-26T10:00']];
        yield 'overlong event title' => [['kind' => 'event', 'title' => str_repeat('x', 151), 'begin' => '2026-10-26T09:00', 'end' => '2026-10-26T10:00']];
        yield 'invalid note type' => [['all_day' => true, 'begin' => '2026-10-26', 'end' => '2026-10-26', 'note' => []]];
        yield 'invalid ID' => [['id' => 'invalid', 'all_day' => true, 'begin' => '2026-10-26', 'end' => '2026-10-26']];
    }

    /** Rejects invalid $data before any persistence or accounting mutation.
     * @param array<string, mixed> $data Invalid request payload
     */
    #[DataProvider('invalidRanges')]
    public function testInvalidRanges(array $data): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $this->expectException(\InvalidArgumentException::class);
        (new TimeOffService($em))->save(new User(), $data);
    }

    /** Prevents editing/deleting another owner's absence even when its numeric ID is known. */
    public function testOwnerIsolation(): void
    {
        $owner = new User();
        $other = $this->createMock(User::class);
        $other->method('getId')->willReturn(99);
        $absence = new ShiftTimeOff($other, new \DateTimeImmutable(), new \DateTimeImmutable(), false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($absence);
        $em->expects(self::never())->method('remove');
        $this->expectException(\InvalidArgumentException::class);
        (new TimeOffService($em))->delete($owner, 1);
    }

    /** Updates an existing owned absence rather than creating another record. */
    public function testEditOwnAbsence(): void
    {
        $owner = new User();
        $absence = new ShiftTimeOff($owner, new \DateTimeImmutable(), new \DateTimeImmutable(), false);
        $absence->id = 7;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('find')->with(ShiftTimeOff::class, 7)->willReturn($absence);
        $service = new TimeOffService($em);
        self::assertSame($absence, $service->save($owner, ['id' => '7', 'all_day' => true, 'begin' => '2026-10-26', 'end' => '2026-10-27']));
        self::assertSame('2026-10-28', $service->record($absence)['end']);
    }

    /** Queries only the specified owner's events and preserves chronological ordering. */
    public function testEventsAreOwnerScoped(): void
    {
        $owner = new User();
        $absence = new ShiftTimeOff($owner, new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-10-27'), true);
        $repository = $this->createMock(\Doctrine\Persistence\ObjectRepository::class);
        $repository->expects(self::once())->method('findBy')->with(['owner' => $owner], ['begin' => 'ASC'])->willReturn([$absence]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $events = (new TimeOffService($em))->events($owner);
        self::assertCount(1, $events);
        self::assertSame('time_off', $events[0]['kind']);
    }

    /** Deletes only the requested owner's absence and flushes the deletion. */
    public function testDeleteOwnAbsence(): void
    {
        $owner = new User();
        $absence = new ShiftTimeOff($owner, new \DateTimeImmutable(), new \DateTimeImmutable(), false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($absence);
        $em->expects(self::once())->method('remove')->with($absence);
        $em->expects(self::once())->method('flush');
        (new TimeOffService($em))->delete($owner, 1);
    }
}

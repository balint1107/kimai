<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting\CalendarSync;

use App\ShiftAccounting\CalendarSync\EventMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Verifies flexible recognition, accounting validation, lossless mapping and conflict detection.
 * @phpstan-import-type Place from EventMapper
 * @phpstan-import-type GoogleEvent from EventMapper
 */
class EventMapperTest extends TestCase
{
    /** Supplies named workplace defaults; their rates must not replace a previously saved override.
     * @return list<Place>
     */
    private function places(): array
    {
        return [['id' => 1, 'name' => 'Víztorony', 'rate' => 3500, 'category' => 'EFO'], ['id' => 2, 'name' => 'Vízöntő', 'rate' => 4000, 'category' => 'Számla']];
    }

    /** Returns a Budapest overnight Google fixture, with $overrides applied to individual top-level fields.
     * @param GoogleEvent $overrides
     * @return GoogleEvent
     */
    private function event(array $overrides = []): array
    {
        return array_replace(['id' => 'fixture', 'summary' => 'VIZTORONY efo', 'description' => '', 'start' => ['dateTime' => '2026-10-24T22:00:00+02:00'], 'end' => ['dateTime' => '2026-10-25T04:00:00+01:00']], $overrides);
    }

    /** Recognizes accent-insensitive names/aliases and calculates elapsed DST instants without wall-clock rounding. */
    public function testRecognizeAndRoundTrip(): void
    {
        $mapper = new EventMapper();
        $value = $mapper->incoming($this->event(['summary' => 'Torony']), $this->places(), ['torony' => 'Víztorony']);
        self::assertNotNull($value);
        self::assertSame(1, $value['workplace'] ?? null);
        self::assertSame(3500, $value['rate'] ?? null);
        self::assertSame(7 * 3600, (int) $value['end'] - (int) $value['begin']);
        $outgoing = $mapper->outgoing($value, 'Víztorony', '12');
        self::assertSame('12', $outgoing['extendedProperties']['private']['kimai_link'] ?? null);
        self::assertSame($value, $mapper->incoming($outgoing, $this->places()));
    }

    /** Preserves explicitly saved zero rates and categories when a renamed linked event omits accounting fields. */
    public function testPreservesSavedDefaults(): void
    {
        $mapper = new EventMapper();
        $previous = $mapper->incoming($this->event(['description' => "Órabér: 0\nKategória: Számla"]), $this->places());
        self::assertNotNull($previous);
        $next = $mapper->incoming($this->event(['summary' => 'Renamed shift']), $this->places(), [], $previous);
        self::assertNotNull($next);
        self::assertSame(0, $next['rate'] ?? null);
        self::assertSame('Számla', $next['category'] ?? null);
        self::assertSame(1, $next['workplace'] ?? null);
        self::assertNull($mapper->incoming($this->event(['summary' => 'Dentist']), $this->places()));
    }

    /** Preserves unmanaged notes and all-day exclusive ends for named holiday/general entries. */
    public function testAllDayHoliday(): void
    {
        $mapper = new EventMapper();
        $event = $this->event(['summary' => 'Family holiday', 'description' => "[Kimai]\nTípus: Szabadság\nSzín: #123abc\n[/Kimai]\n\nMy notes", 'start' => ['date' => '2026-03-29'], 'end' => ['date' => '2026-03-31']]);
        $value = $mapper->incoming($event, $this->places());
        self::assertNotNull($value);
        self::assertSame('time_off', $value['kind']);
        self::assertSame('My notes', $value['note']);
        self::assertSame('2026-03-31', $value['end']);
        self::assertSame($value, $mapper->incoming($mapper->outgoing($value, '', '7'), $this->places()));
    }

    /** Handles Google/phone HTML editors folding managed fields onto the same line. */
    public function testFoldedDescription(): void
    {
        $value = (new EventMapper())->incoming($this->event(['description' => '<p>[Kimai] Típus: Helyettesítés Munkahely: Víztorony Kategória: Számla Órabér: 4200 Törölt: nem [/Kimai]</p><p>Personal note</p>']), $this->places());
        self::assertNotNull($value);
        self::assertSame(4200, $value['rate'] ?? null);
        self::assertSame('Számla', $value['category'] ?? null);
        self::assertSame('Personal note', $value['note']);
    }

    /** Checks rejected candidates never become guessed accounting records.
     * @param GoogleEvent $overrides
     */
    #[DataProvider('invalidEvents')]
    public function testInvalidCandidates(array $overrides): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EventMapper())->incoming($this->event($overrides), $this->places());
    }

    /** Provides ambiguous names, unknown categories, fractional rates, missing dates and all-day paid shifts.
     * @return iterable<string, array{GoogleEvent}>
     */
    public static function invalidEvents(): iterable
    {
        yield 'ambiguous' => [['summary' => 'Víztorony Vízöntő']];
        yield 'unknown workplace' => [['description' => 'Munkahely: Ismeretlen']];
        yield 'fractional rate' => [['description' => 'Órabér: 3500.5']];
        yield 'negative rate' => [['description' => 'Órabér: -1']];
        yield 'unknown category' => [['description' => 'Kategória: Unknown']];
        yield 'ambiguous category' => [['summary' => 'Víztorony EFO Számla']];
        yield 'conflicting fields' => [['description' => "Órabér: 1\nRate: 2"]];
        yield 'unknown type' => [['description' => 'Típus: Unknown']];
        yield 'all-day shift' => [['start' => ['date' => '2026-10-27'], 'end' => ['date' => '2026-10-28']]];
        yield 'missing end' => [['end' => []]];
        yield 'reversed' => [['end' => ['dateTime' => '2026-10-24T21:00:00+02:00']]];
    }

    /** Merges independent edits, detects same-field races and rejects incompatible merged date ranges. */
    public function testThreeWayMerge(): void
    {
        $mapper = new EventMapper();
        $base = $mapper->incoming($this->event(), $this->places());
        self::assertNotNull($base);
        $local = array_replace($base, ['rate' => 4200]);
        $remote = array_replace($base, ['note' => 'Phone edit']);
        $merged = $mapper->merge($base, $local, $remote);
        self::assertSame([], $merged['conflicts']);
        self::assertSame(4200, $merged['value']['rate'] ?? null);
        self::assertSame('Phone edit', $merged['value']['note']);
        self::assertSame(['rate'], $mapper->merge($base, $local, array_replace($remote, ['rate' => 5000]))['conflicts']);
        self::assertContains('dates', $mapper->merge($base, array_replace($base, ['begin' => (string) ((int) $base['end'] - 60)]), array_replace($base, ['end' => (string) ((int) $base['begin'] + 60)]))['conflicts']);
    }
}

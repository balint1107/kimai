<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting\CalendarSync;

use App\Entity\ShiftTimeOff;
use App\Entity\Timesheet;
use App\Entity\User;
use App\ShiftAccounting\ShiftService;
use App\ShiftAccounting\TimeOffService;
use Doctrine\ORM\EntityManagerInterface;

/** Owner-checked adapter to existing native accounting services; sync never bypasses record validation.
 * @phpstan-import-type Value from EventMapper
 */
final class LocalCalendar
{
    /** Injects native $shifts/$timeOff and persistence $em; no Google network calls occur here. */
    public function __construct(private EntityManagerInterface $em, private ShiftService $shifts, private TimeOffService $timeOff)
    {
    }

    /** Returns all complete managed shifts and calendar-only records owned by $owner, keyed by stable local identity.
     * @return array<string, Value>
     */
    public function records(User $owner): array
    {
        $result = [];
        foreach ($this->shifts->shifts($owner) as $shift) {
            if ($shift->getBegin() === null || $shift->getEnd() === null || $shift->getProject()?->getCustomer() === null || $shift->getActivity() === null) {
                continue;
            }
            $result['shift:' . $shift->getId()] = ['kind' => 'shift', 'begin' => $shift->getBegin()->format('U'), 'end' => $shift->getEnd()->format('U'), 'all_day' => false, 'note' => $shift->getDescription() ?? '', 'workplace' => (int) $shift->getProject()->getCustomer()->getId(), 'category' => $shift->getActivity()->getName() ?? '', 'rate' => (int) $shift->getHourlyRate(), 'cancelled' => $this->shifts->state($shift) === 'cancelled'];
        }
        $zone = new \DateTimeZone('Europe/Budapest');
        foreach ($this->em->getRepository(ShiftTimeOff::class)->findBy(['owner' => $owner]) as $event) {
            $result[$event->kind . ':' . $event->id] = ['kind' => $event->kind, 'begin' => $event->allDay ? $event->begin->setTimezone($zone)->format('Y-m-d') : $event->begin->format('U'), 'end' => $event->allDay ? $event->end->setTimezone($zone)->format('Y-m-d') : $event->end->format('U'), 'all_day' => $event->allDay, 'note' => $event->note, 'title' => $event->title ?: ($event->note ?: ($event->kind === 'time_off' ? 'Szabadság' : 'Esemény')), 'color' => $event->color ?? ($event->kind === 'time_off' ? '#ae3ec9' : '#0ca678')];
        }

        return $result;
    }

    /** Applies normalized $value for $owner to optional $key; $expected detects intervening local edits inside a sync transaction; returns stable identity, preserving explicit rate after native recalculation.
     * @param Value $value
     * @param Value|null $expected
     */
    public function save(User $owner, array $value, ?string $key, ?array $expected = null): string
    {
        if ($key !== null && $expected !== null) {
            $entity = $this->em->find($value['kind'] === 'shift' ? Timesheet::class : ShiftTimeOff::class, (int) explode(':', $key)[1]);
            if ($entity === null) {
                throw new \RuntimeException('Local record changed during synchronization');
            }
            $this->em->lock($entity, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($entity);
            if (($this->records($owner)[$key] ?? null) !== $expected) {
                throw new \RuntimeException('Local record changed during synchronization');
            }
        }
        $zone = new \DateTimeZone('Europe/Budapest');
        $date = fn ($v) => (new \DateTimeImmutable('@' . $v))->setTimezone($zone)->format(\DateTimeInterface::ATOM);
        if ($value['kind'] === 'shift') {
            $existing = $key === null ? null : $this->em->find(Timesheet::class, (int) explode(':', $key)[1]);
            $record = $this->shifts->save($owner, array_replace($value, ['begin' => $date($value['begin']), 'end' => $date($value['end'])]), $existing);
            // Native category changes may reset an unchanged explicit override; the synchronized saved rate is authoritative.
            if ($record->getHourlyRate() !== (float) ($value['rate'] ?? 0)) {
                $record->setHourlyRate((float) ($value['rate'] ?? 0));
                $record->setRate((($record->getEnd()?->getTimestamp() ?? 0) - ($record->getBegin()?->getTimestamp() ?? 0)) / 3600 * ($value['rate'] ?? 0));
                $this->em->flush();
            }

            return 'shift:' . $record->getId();
        }
        $allDay = $value['all_day'];
        $data = $value;
        $data['begin'] = $allDay ? $value['begin'] : $date($value['begin']);
        $data['end'] = $allDay ? (new \DateTimeImmutable($value['end'], $zone))->modify('-1 day')->format('Y-m-d') : $date($value['end']);
        if ($key !== null) {
            $data['id'] = (int) explode(':', $key)[1];
        }
        $record = $this->timeOff->save($owner, $data);

        return $record->kind . ':' . $record->id;
    }

    /** Cancels a paid shift or deletes a calendar-only $key for $owner; accounting history is retained. */
    public function remoteDelete(User $owner, string $key): void
    {
        $records = $this->records($owner);
        if (!isset($records[$key])) {
            return;
        }
        if ($records[$key]['kind'] === 'shift') {
            $this->save($owner, array_replace($records[$key], ['cancelled' => true]), $key);
        } else {
            $this->timeOff->delete($owner, (int) explode(':', $key)[1]);
        }
    }
}

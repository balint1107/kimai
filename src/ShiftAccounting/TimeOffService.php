<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting;

use App\Entity\ShiftTimeOff;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Persists owner-scoped calendar absences independently of shift accounting. */
final class TimeOffService
{
    /** Uses $em for absence persistence; callers enforce session authentication and CSRF. */
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Returns only $owner's absences as FullCalendar events, regardless of earnings filters.
     * @return list<array<string, mixed>>
     */
    public function events(User $owner): array
    {
        return array_map($this->record(...), $this->em->getRepository(ShiftTimeOff::class)->findBy(['owner' => $owner], ['begin' => 'ASC']));
    }

    /** Saves $data for $owner; dates are Budapest-local, all-day end dates inclusive; returns the saved absence.
     * @param array<string, mixed> $data id optional; begin/end date or minute datetime strings; all_day boolean; note optional
     */
    public function save(User $owner, array $data): ShiftTimeOff
    {
        $allDay = ($data['all_day'] ?? false) === true;
        $format = $allDay ? 'Y-m-d' : 'Y-m-d\TH:i';
        $zone = new \DateTimeZone('Europe/Budapest');
        $dates = [];
        foreach (['begin', 'end'] as $key) {
            $value = $data[$key] ?? null;
            $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!' . $format, $value, $zone) : false;
            if ($date === false || $date->format($format) !== $value) {
                throw new \InvalidArgumentException('shift.invalid_time');
            }
            $dates[$key] = $date;
        }
        if ($allDay) {
            $dates['end'] = $dates['end']->modify('+1 day');
        }
        $note = $data['note'] ?? '';
        $idValue = $data['id'] ?? null;
        $id = filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!\is_string($note) || ($idValue !== null && $idValue !== '' && $id === false)) {
            throw new \InvalidArgumentException('shift.invalid_time');
        }
        $note = trim($note);
        if ($dates['end'] <= $dates['begin'] || mb_strlen($note) > 200) {
            throw new \InvalidArgumentException('shift.invalid_time');
        }
        $utc = new \DateTimeZone('UTC');
        $absence = $id === false ? new ShiftTimeOff($owner, $dates['begin'], $dates['end'], $allDay) : $this->owned($owner, $id);
        $absence->begin = $dates['begin']->setTimezone($utc);
        $absence->end = $dates['end']->setTimezone($utc);
        $absence->allDay = $allDay;
        $absence->note = $note;
        $this->em->persist($absence);
        $this->em->flush();

        return $absence;
    }

    /** Deletes $id only when it belongs to $owner; invalid IDs fail without exposing another user's data. */
    public function delete(User $owner, int $id): void
    {
        $this->em->remove($this->owned($owner, $id));
        $this->em->flush();
    }

    /** Finds $id for $owner or throws the same validation error for missing/foreign IDs. */
    private function owned(User $owner, int $id): ShiftTimeOff
    {
        $absence = $this->em->find(ShiftTimeOff::class, $id);
        if ($absence === null || $absence->owner->getId() !== $owner->getId()) {
            throw new \InvalidArgumentException('shift.invalid_time');
        }

        return $absence;
    }

    /** Converts $absence into a non-draggable purple event; all-day end is exclusive per FullCalendar.
     * @return array<string, mixed>
     */
    public function record(ShiftTimeOff $absence): array
    {
        $zone = new \DateTimeZone('Europe/Budapest');
        $format = $absence->allDay ? 'Y-m-d' : \DateTimeInterface::ATOM;

        return [
            'id' => 'timeoff-' . $absence->id, 'absence_id' => $absence->id, 'kind' => 'time_off',
            'title' => $absence->note, 'start' => $absence->begin->setTimezone($zone)->format($format),
            'end' => $absence->end->setTimezone($zone)->format($format), 'allDay' => $absence->allDay,
            'editable' => false, 'color' => '#7952b3', 'classNames' => ['shift-time-off'],
        ];
    }
}

<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\CustomerMeta;
use App\Entity\Project;
use App\Entity\ShiftWorkplace;
use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use App\Entity\User;
use App\Timesheet\TimesheetService;
use Doctrine\ORM\EntityManagerInterface;

/** Maps simple workplace shifts onto native Kimai customers, projects, activities and timesheets. */
final class ShiftService
{
    public const UNKNOWN = 'Unknown';
    public const CATEGORIES = ['EFO', 'Számla'];

    /** Injects $em for persistence and $timesheets for Kimai validation and rate calculation. */
    public function __construct(private EntityManagerInterface $em, private TimesheetService $timesheets)
    {
    }

    /** Returns $owner's named managed workplaces and private defaults; legacy missing-value placeholders stay review-only.
     * @phpstan-impure
     * @return list<array{id:int|null,name:string,rate:float,category:mixed,color:string}>
     */
    public function workplaces(User $owner): array
    {
        $result = [];
        foreach ($this->em->getRepository(ShiftWorkplace::class)->findBy(['owner' => $owner]) as $workplace) {
            $customer = $workplace->customer;
            if ($customer->getMetaField('shift_workplace')?->getValue() !== '1' || !$this->knownWorkplace($customer->getName())) {
                continue;
            }
            $result[] = [
                'id' => $customer->getId(), 'name' => $customer->getName() ?? '',
                'rate' => is_numeric($customer->getMetaField('shift_rate')?->getValue()) ? (float) $customer->getMetaField('shift_rate')->getValue() : 0.0,
                'category' => $customer->getMetaField('shift_category')?->getValue() ?? 'EFO',
                'color' => $customer->getColor() ?? '#206bc4',
            ];
        }

        usort($result, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $result;
    }

    /** Creates or updates $owner's $name with a nonnegative whole-HUF/hour $rate and $category; returns its customer. */
    public function workplace(User $owner, string $name, float $rate = 0, string $category = 'EFO', ?string $color = null, ?int $id = null): Customer
    {
        $name = trim($name);
        if (!$this->knownWorkplace($name) || mb_strlen($name) > 150 || !is_finite($rate) || $rate < 0 || floor($rate) !== $rate || !\in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('shift.invalid_workplace');
        }
        $repository = $this->em->getRepository(ShiftWorkplace::class);
        $key = ShiftWorkplace::key($name);
        $named = $repository->findOneBy(['owner' => $owner, 'nameKey' => $key]);
        $workplace = $id === null ? $named : $repository->findOneBy(['owner' => $owner, 'customer' => $id]);
        if (($id !== null && $workplace === null) || ($named !== null && $workplace !== $named)) {
            throw new \InvalidArgumentException('shift.invalid_workplace');
        }
        $customer = $workplace->customer ?? new Customer($name);
        $workplace ??= new ShiftWorkplace($owner, $customer, $key);
        $workplace->nameKey = $key;
        $customer->setName($name);
        $customer->setCurrency('HUF');
        $customer->setCountry('HU');
        $customer->setTimezone('Europe/Budapest');
        $palette = ['#206bc4', '#2fb344', '#ae3ec9', '#d63939', '#f76707', '#0ca678', '#4263eb', '#d6336c', '#7950f2'];
        $customer->setColor($color !== null && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? $color : ($customer->getColor() ?? $palette[abs(crc32($name)) % \count($palette)]));
        foreach (['shift_workplace' => '1', 'shift_rate' => (string) $rate, 'shift_category' => $category] as $key => $value) {
            $meta = $customer->getMetaField($key) ?? new CustomerMeta();
            $meta->setName($key)->setValue($value);
            $customer->setMetaField($meta);
            $this->em->persist($meta);
        }
        $this->em->persist($customer);
        $this->em->persist($workplace);
        $this->em->flush();
        $this->project($customer);

        return $customer;
    }

    /** Finds or creates the sole managed project for $customer; returns the persisted project. */
    private function project(Customer $customer): Project
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['customer' => $customer, 'name' => 'Shifts']);
        if ($project === null) {
            $project = (new Project())->setCustomer($customer)->setName('Shifts');
            $this->em->persist($project);
            $this->em->flush();
        }

        return $project;
    }

    /** Returns the global activity for canonical $category, creating it if necessary. */
    private function activity(string $category): Activity
    {
        $activity = $this->em->getRepository(Activity::class)->findOneBy(['name' => $category, 'project' => null]);
        if ($activity === null) {
            $activity = (new Activity())->setName($category);
            $this->em->persist($activity);
            $this->em->flush();
        }

        return $activity;
    }

    /** Parses ISO/local $value in Budapest; rejects missing/invalid or normalized nonexistent local times. */
    public function date(string $value): \DateTime
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('shift.invalid_time');
        }
        try {
            $date = new \DateTime($value, new \DateTimeZone('Europe/Budapest'));
        } catch (\Exception) {
            throw new \InvalidArgumentException('shift.invalid_time');
        }
        if ($date->format('Y-m-d\TH:i') !== substr($value, 0, 16)) {
            throw new \InvalidArgumentException('shift.invalid_time');
        }
        $date->setTimezone(new \DateTimeZone('Europe/Budapest'));

        return $date;
    }

    /** Saves $owner's known workplace/category $data with a whole-HUF hourly rate; $existing must belong to $owner.
     * @param array<string, mixed> $data
     */
    public function save(User $owner, array $data, ?Timesheet $existing = null): Timesheet
    {
        if ($existing !== null && $existing->getUser()?->getId() !== $owner->getId()) {
            throw new \InvalidArgumentException('shift.not_found');
        }
        $workplaceId = $data['workplace'] ?? null;
        if (!is_numeric($workplaceId)) {
            throw new \InvalidArgumentException('shift.invalid_workplace');
        }
        $customer = $this->em->getRepository(ShiftWorkplace::class)->findOneBy(['owner' => $owner, 'customer' => (int) $workplaceId])?->customer;
        if ($customer === null || $customer->getMetaField('shift_workplace')?->getValue() !== '1' || !$this->knownWorkplace($customer->getName())) {
            throw new \InvalidArgumentException('shift.invalid_workplace');
        }
        $category = \is_string($data['category'] ?? null) ? $data['category'] : '';
        if (!\in_array($category, self::CATEGORIES, true) || !is_numeric($data['rate'] ?? null)) {
            throw new \InvalidArgumentException('shift.invalid_rate');
        }
        $rate = (float) $data['rate'];
        if (!is_finite($rate) || $rate < 0 || floor($rate) !== $rate) {
            throw new \InvalidArgumentException('shift.invalid_rate');
        }
        $begin = $this->date(\is_string($data['begin'] ?? null) ? $data['begin'] : '');
        $end = $this->date(\is_string($data['end'] ?? null) ? $data['end'] : '');
        $note = $data['note'] ?? '';
        if (!\is_string($note) || $end <= $begin || mb_strlen($note) > 2000) {
            throw new \InvalidArgumentException('shift.invalid_time');
        }
        $timesheet = $existing ?? $this->timesheets->createNewTimesheet($owner);
        $timesheet->setProject($this->project($customer));
        $timesheet->setActivity($this->activity($category));
        $timesheet->setBegin($begin);
        $timesheet->setEnd($end);
        $timesheet->setHourlyRate($rate);
        $timesheet->setFixedRate(null);
        $timesheet->setDescription($note);
        $this->meta($timesheet, 'shift_cancelled', !empty($data['cancelled']) ? '1' : '0');
        $timesheet->setBillableMode(Timesheet::BILLABLE_YES);
        $this->timesheets->validateTimesheet($timesheet);

        return $this->timesheets->saveTimesheet($timesheet);
    }

    /** Sets private metadata $name/$value on $timesheet; caller persists the owning timesheet. */
    public function meta(Timesheet $timesheet, string $name, string $value): void
    {
        $meta = $timesheet->getMetaField($name) ?? new TimesheetMeta();
        $meta->setName($name)->setValue($value);
        $timesheet->setMetaField($meta);
        $this->em->persist($meta);
    }

    /** Returns $owner's complete managed shifts within [from, to); legacy missing-field records remain available for review.
     * @return list<Timesheet>
     */
    public function shifts(User $owner, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $query = $this->em->createQueryBuilder()->select('t', 'p', 'c', 'a', 'm')->from(Timesheet::class, 't')
            ->join('t.project', 'p')->join('p.customer', 'c')->join('t.activity', 'a')->leftJoin('t.meta', 'm')
            ->join('c.meta', 'managed', 'WITH', 'managed.name = :managed AND managed.value = :enabled')
            ->setParameter('managed', 'shift_workplace')->setParameter('enabled', '1')
            ->innerJoin(ShiftWorkplace::class, 'ownership', 'WITH', 'ownership.customer = c AND ownership.owner = :owner')
            ->where('t.user = :owner')->setParameter('owner', $owner)->orderBy('t.begin', 'ASC');
        if ($from !== null) {
            $query->andWhere('t.begin >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $query->andWhere('t.begin < :to')->setParameter('to', $to);
        }

        return array_values(array_filter($query->getQuery()->getResult(), $this->isComplete(...)));
    }

    /** Returns whether $shift has a real workplace and an EFO/Számla category; false keeps legacy data review-only. */
    public function isComplete(Timesheet $shift): bool
    {
        return $this->knownWorkplace($shift->getProject()?->getCustomer()?->getName())
            && \in_array($shift->getActivity()?->getName(), self::CATEGORIES, true);
    }

    /** Accepts nonblank $name except reserved missing-value placeholders in either interface language. */
    private function knownWorkplace(?string $name): bool
    {
        return $name !== null && !\in_array(mb_strtolower(trim($name)), ['', 'unknown', 'ismeretlen'], true);
    }

    /** Returns the time-derived state of $shift at optional $now; cancellation always takes precedence. */
    public function state(Timesheet $shift, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        if ($shift->getMetaField('shift_cancelled')?->getValue() === '1') {
            return 'cancelled';
        }
        if ($shift->getEnd() !== null && $shift->getEnd() <= $now) {
            return 'completed';
        }

        return $shift->getBegin() <= $now ? 'in_progress' : 'planned';
    }

    /** Serializes $shift with a workplace-only calendar title and category/rate description; saved free notes and non-blocking overlap warnings stay separate.
     * @return array<string, mixed>
     */
    public function record(Timesheet $shift): array
    {
        $state = $this->state($shift);

        return [
            'id' => $shift->getId(), 'title' => $shift->getProject()?->getCustomer()?->getName(),
            'description' => $shift->getActivity()?->getName() . ', ' . (int) $shift->getHourlyRate() . (($shift->getDescription() ?? '') !== '' ? "\n\n" . $shift->getDescription() : ''),
            'start' => $shift->getBegin()?->format(\DateTimeInterface::ATOM), 'end' => $shift->getEnd()?->format(\DateTimeInterface::ATOM),
            'color' => $state === 'cancelled' ? '#868e96' : $shift->getProject()?->getCustomer()?->getColor(),
            'workplace' => $shift->getProject()?->getCustomer()?->getId(), 'category' => $shift->getActivity()?->getName(),
            'rate' => $shift->getHourlyRate(), 'amount' => $shift->getRate(), 'seconds' => $shift->getDuration(),
            'note' => $shift->getDescription(), 'cancelled' => $state === 'cancelled', 'state' => $state,
            'review' => $shift->getMetaField('shift_review')?->getValue() ?? '',
        ];
    }
}

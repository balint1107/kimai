<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\ShiftImportBatch;
use App\Entity\ShiftImportItem;
use App\Entity\Timesheet;
use App\ShiftAccounting\ShiftReport;
use App\ShiftAccounting\ShiftService;
use App\ShiftAccounting\WorkbookImport;
use App\Timesheet\TimesheetService;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Authenticated single-owner accounting UI; all mutations use the existing session and CSRF tokens. */
#[Route(path: '/shifts')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ShiftController extends AbstractController
{
    /** Injects persistence, shift/import/report services and $translator for safe localized errors. */
    public function __construct(
        private EntityManagerInterface $em,
        private ShiftService $shifts,
        private ShiftReport $reports,
        private WorkbookImport $imports,
        private TimesheetService $timesheets,
        private TranslatorInterface $translator,
    ) {
    }

    /** Displays the calendar/report shell for the authenticated owner; $request selects the tab. */
    #[Route(path: '', name: 'shift_calendar', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $review = null;
        if ($request->query->getInt('review') > 0) {
            $item = $this->em->find(ShiftImportItem::class, $request->query->getInt('review'));
            if ($item === null || $item->batch->owner->getId() !== $this->getUser()->getId()) {
                throw $this->createNotFoundException();
            }
            $review = $item->timesheet !== null ? $this->shifts->record($item->timesheet) : $item->payload;
            $review['review_item'] = $item->id;
            if ($item->timesheet === null) {
                foreach ($this->shifts->workplaces() as $place) {
                    if ($place['name'] === $item->payload['workplace']) {
                        $review['workplace'] = $place['id'];
                    }
                }
            }
        }

        return $this->render('shifts/index.html.twig', [
            'page_setup' => new PageSetup('shift.title'), 'places' => $this->shifts->workplaces(),
            'tab' => $request->query->get('tab', 'calendar'),
            'review' => $review,
            'batches' => $this->em->getRepository(ShiftImportBatch::class)->findBy(['owner' => $this->getUser()], ['id' => 'DESC']),
        ]);
    }

    /** Returns complete owner-scoped records, flat reports and grouped completed/total summaries filtered by $request. */
    #[Route(path: '/data', name: 'shift_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        try {
            $records = $this->filtered($request);
            $groups = $this->reports->build($records);

            return $this->json(['events' => array_map($this->shifts->record(...), $records), 'groups' => $groups, 'summary' => ['months' => $this->reports->summary($groups['months']), 'years' => $this->reports->summary($groups['years'])], 'places' => $this->shifts->workplaces()]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e);
        }
    }

    /** Validates JSON $request and saves a new or owner-owned existing shift; returns its calendar record. */
    #[Route(path: '/save', name: 'shift_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $this->csrf($request);
        $data = $request->toArray();
        try {
            $existing = empty($data['id']) ? null : $this->owned((int) $data['id']);
            $item = null;
            if (!empty($data['review_item'])) {
                $item = $this->em->find(ShiftImportItem::class, (int) $data['review_item']);
                if ($item === null || $item->batch->owner->getId() !== $this->getUser()->getId() || ($item->timesheet !== null && $item->timesheet !== $existing)) {
                    throw $this->createAccessDeniedException();
                }
                $place = $this->em->find(\App\Entity\Customer::class, (int) ($data['workplace'] ?? 0));
                if ($place?->getName() === ShiftService::UNKNOWN || ($data['category'] ?? '') === ShiftService::UNKNOWN) {
                    throw new \InvalidArgumentException('shift.invalid_workplace');
                }
            }
            $shift = $this->shifts->save($this->getUser(), $data, $existing);
            if ($item !== null) {
                $item->timesheet = $shift;
                $item->resolved = true;
                $this->shifts->meta($shift, 'shift_review', '');
                $this->em->persist($shift);
                $this->em->persist($item);
                $this->em->flush();
            }

            return $this->json($this->shifts->record($shift));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e);
        } catch (\App\Validator\ValidationFailedException $e) {
            return $this->json(['error' => $this->translator->trans('shift.validation_error')], 422);
        }
    }

    /** Deletes the owner's shift selected by JSON $request; import provenance remains intact. */
    #[Route(path: '/delete', name: 'shift_delete', methods: ['POST'])]
    public function delete(Request $request): JsonResponse
    {
        $this->csrf($request);
        $shift = $this->owned((int) ($request->toArray()['id'] ?? 0));
        foreach ($this->em->getRepository(ShiftImportItem::class)->findBy(['timesheet' => $shift]) as $item) {
            $item->resolved = false;
            $this->em->persist($item);
        }
        $this->timesheets->deleteTimesheet($shift);

        return $this->json(['deleted' => true]);
    }

    /** Saves workplace defaults from JSON $request without changing existing shift rates. */
    #[Route(path: '/workplace', name: 'shift_workplace', methods: ['POST'])]
    public function workplace(Request $request): JsonResponse
    {
        $this->csrf($request);
        $data = $request->toArray();
        try {
            if (!is_numeric($data['rate'] ?? null)) {
                throw new \InvalidArgumentException('shift.invalid_rate');
            }
            $this->shifts->workplace((string) ($data['name'] ?? ''), (float) $data['rate'], (string) ($data['category'] ?? 'EFO'), (string) ($data['color'] ?? ''), empty($data['id']) ? null : (int) $data['id']);

            return $this->json($this->shifts->workplaces());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e);
        }
    }

    /** Accepts a private XLSX upload from multipart $request and redirects to its persisted preview. */
    #[Route(path: '/upload', name: 'shift_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('shifts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $file = $request->files->get('workbook');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
            throw $this->createNotFoundException();
        }
        try {
            $batch = $this->imports->preview($this->getUser(), $file->getPathname(), $file->getClientOriginalName());

            return $this->redirectToRoute('shift_import', ['id' => $batch->id]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectToRoute('shift_calendar', ['tab' => 'imports']);
        }
    }

    /** Displays source rows and reconciliation for the owner's import batch $id. */
    #[Route(path: '/imports/{id}', name: 'shift_import', methods: ['GET'])]
    public function import(int $id): Response
    {
        $batch = $this->batch($id);

        return $this->render('shifts/import.html.twig', [
            'page_setup' => new PageSetup('shift.imports'), 'batch' => $batch,
            'items' => $this->imports->items($batch), 'reconciliation' => $this->imports->reconciliation($batch),
        ]);
    }

    /** Commits previewed batch $id after CSRF validation of $request; repeat submits are idempotent. */
    #[Route(path: '/imports/{id}/commit', name: 'shift_import_commit', methods: ['POST'])]
    public function commit(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('shifts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->imports->commit($this->batch($id));

        return $this->redirectToRoute('shift_import', ['id' => $id]);
    }

    /** Downloads source reconciliation for the owner's batch $id without exposing other uploads. */
    #[Route(path: '/imports/{id}/reconciliation', name: 'shift_reconciliation', methods: ['GET'])]
    public function reconciliation(int $id): JsonResponse
    {
        $response = $this->json($this->imports->reconciliation($this->batch($id)));
        $response->headers->set('Content-Disposition', 'attachment; filename="reconciliation.json"');

        return $response;
    }

    /** Exports filtered records from $request as $format (xlsx/csv), protecting text against formula injection. */
    #[Route(path: '/export/{format}', name: 'shift_export', requirements: ['format' => 'xlsx|csv'], methods: ['GET'])]
    public function export(Request $request, string $format): Response
    {
        $records = $this->filtered($request);
        if ($format === 'xlsx') {
            return $this->fileStream(function () use ($records): void {
                $book = $this->reports->workbook($records);
                (new Xlsx($book))->save('php://output');
                $book->disconnectWorksheets();
            }, 'shifts.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        return $this->fileStream(function () use ($records): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open export output');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Date', 'Workplace', 'Begin', 'End', 'HUF/hour', 'HUF', 'Category', 'Hours', 'State', 'Note']);
            foreach ($records as $record) {
                $cells = [$record->getBegin()?->format('Y-m-d'), $record->getProject()?->getCustomer()?->getName(), $record->getBegin()?->format(\DateTimeInterface::ATOM), $record->getEnd()?->format(\DateTimeInterface::ATOM), $record->getHourlyRate(), $record->getRate(), $record->getActivity()?->getName(), ($record->getDuration() ?? 0) / 3600, $this->shifts->state($record), $record->getDescription()];
                $cells = array_map(static fn ($value) => \is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) === 1 ? "'" . $value : $value, $cells);
                fputcsv($stream, $cells);
            }
            fclose($stream);
        }, 'shifts.csv', 'text/csv; charset=utf-8');
    }

    /** Streams bytes emitted by $writer with download $filename and $type, disabling intermediary caching. */
    private function fileStream(callable $writer, string $filename, string $type): Response
    {
        return new \Symfony\Component\HttpFoundation\StreamedResponse($writer, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="' . $filename . '"', 'Cache-Control' => 'private, no-store']);
    }

    /** Applies optional date/workplace/category/state filters from $request.
     * @return list<Timesheet>
     */
    private function filtered(Request $request): array
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');
        $records = $this->shifts->shifts($this->getUser(), $from ? $this->shifts->date($from . 'T00:00') : null, $to ? $this->shifts->date($to . 'T00:00')->modify('+1 day') : null);

        return array_values(array_filter($records, function (Timesheet $shift) use ($request): bool {
            return (!$request->query->get('workplace') || $shift->getProject()?->getCustomer()?->getId() === (int) $request->query->get('workplace'))
                && (!$request->query->get('category') || $shift->getActivity()?->getName() === $request->query->get('category'))
                && (!$request->query->get('state') || $this->shifts->state($shift) === $request->query->get('state'));
        }));
    }

    /** Resolves timesheet $id only for the logged-in owner; returns 404 otherwise. */
    private function owned(int $id): Timesheet
    {
        $shift = $this->em->find(Timesheet::class, $id);
        if ($shift === null || $shift->getUser()?->getId() !== $this->getUser()->getId()) {
            throw $this->createNotFoundException();
        }

        return $shift;
    }

    /** Resolves import batch $id only for the logged-in owner; returns 404 otherwise. */
    private function batch(int $id): ShiftImportBatch
    {
        $batch = $this->em->find(ShiftImportBatch::class, $id);
        if ($batch === null || $batch->owner->getId() !== $this->getUser()->getId()) {
            throw $this->createNotFoundException();
        }

        return $batch;
    }

    /** Requires the shifts CSRF header on JSON mutation $request; rejects invalid/missing tokens. */
    private function csrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('shifts', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    /** Converts a known validation $error to a localized 422 JSON response without exception details. */
    private function error(\InvalidArgumentException $error): JsonResponse
    {
        return $this->json(['error' => $this->translator->trans($error->getMessage())], 422);
    }
}

<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting\CalendarSync;

/** Deterministic phone-friendly event recognition, managed-description serialization and three-way merge.
 * @phpstan-type Value array{kind:string,begin:string,end:string,all_day:bool,note:string,workplace?:int,category?:string,rate?:int,cancelled?:bool,title?:string,color?:string}
 * @phpstan-type Place array{id:int|null,name:string,rate:float|int,category:mixed,color?:string}
 * @phpstan-type GoogleEvent array{accessRole?:string,id?:string,etag?:string,status?:string,summary?:string,description?:string,location?:string,start?:array{date?:string,dateTime?:string,timeZone?:string},end?:array{date?:string,dateTime?:string,timeZone?:string},recurrence?:list<string>,recurringEventId?:string,htmlLink?:string,extendedProperties?:array{private?:array<string,string>,shared?:array<string,string>},...}
 */
final class EventMapper
{
    /** Normalizes $value for case/accent/punctuation-insensitive whole-token alias matching. */
    public function normalize(string $value): string
    {
        $value = strtr(mb_strtolower($value), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '');
    }

    /** Maps Google $event using $places/$aliases; $previous preserves saved overrides; $plainEvents enables ordinary-event fallback in explicitly opted-in calendars; $calendarRole makes a dedicated holiday calendar authoritative. Returns null for unrelated events, throws for ambiguous/invalid candidates.
     * @param GoogleEvent $event
     * @param list<Place> $places
     * @param array<string, string> $aliases
     * @param Value|array{} $previous
     * @param 'mixed'|'work'|'holiday' $calendarRole
     * @return Value|null
     */
    public function incoming(array $event, array $places, array $aliases = [], array $previous = [], bool $plainEvents = false, string $calendarRole = 'mixed'): ?array
    {
        $description = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", (string) ($event['description'] ?? ''))), ENT_QUOTES | ENT_HTML5);
        $fields = [];
        preg_match('/\[Kimai\](.*?)\[\/Kimai\]/si', $description, $block);
        $keys = 'Típus|Tipus|Type|Munkahely|Workplace|Kategória|Kategoria|Category|Órabér|Oraber|Rate|Szín|Szin|Color|Törölt|Torolt|Cancelled';
        // Phone/calendar editors may fold description newlines into spaces or HTML paragraphs.
        $pattern = isset($block[1]) ? '/(?:^|\s)(' . $keys . ')\s*:\s*(.*?)(?=\s+(?:' . $keys . ')\s*:|$)/isu' : '/^\s*(' . $keys . ')\s*:\s*(.*?)\s*$/imu';
        preg_match_all($pattern, $block[1] ?? $description, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $this->normalize($match[1]);
            $key = ['tipus' => 'type', 'munkahely' => 'workplace', 'kategoria' => 'category', 'oraber' => 'rate', 'szin' => 'color', 'torolt' => 'cancelled'][$key] ?? $key;
            $field = trim($match[2]);
            if (isset($fields[$key]) && $fields[$key] !== $field) {
                throw new \InvalidArgumentException('Conflicting description fields');
            }
            $fields[$key] = $field;
        }
        if (isset($fields['cancelled']) && !\in_array($this->normalize($fields['cancelled']), ['0', '1', 'true', 'false', 'igen', 'nem', 'yes', 'no'], true)) {
            throw new \InvalidArgumentException('Invalid cancellation flag');
        }
        $text = ' ' . $this->normalize(($event['summary'] ?? '') . ' ' . ($event['location'] ?? '')) . ' ';
        $titleText = ' ' . $this->normalize($event['summary'] ?? '') . ' ';
        $titleMatches = [];
        $matches = [];
        foreach ($places as $place) {
            $names = [$place['name']];
            foreach ($aliases as $alias => $name) {
                if ($name === $place['name']) {
                    $names[] = $alias;
                }
            }
            foreach ($names as $name) {
                if (isset($fields['workplace']) ? $this->normalize($fields['workplace']) === $this->normalize($name) : str_contains($text, ' ' . $this->normalize($name) . ' ')) {
                    $matches[$place['id']] = $place;
                    if (!isset($fields['workplace']) && str_contains($titleText, ' ' . $this->normalize($name) . ' ')) {
                        $titleMatches[$place['id']] = $place;
                    }
                }
            }
        }
        if ($titleMatches !== []) {
            $matches = $titleMatches;
        }
        $hidden = $event['extendedProperties']['private'] ?? [];
        $title = ' ' . $this->normalize($event['summary'] ?? '') . ' ';
        $holiday = $calendarRole === 'mixed' && (str_contains($title, ' szabadsag ') || str_contains($title, ' szabi ') || str_contains($title, ' holiday '));
        $shiftHint = str_contains($text, ' helyettesites ') || preg_match('/^\s*(EFO|Sz[aá]mla)\b/iu', $description) === 1;
        $kind = match ($this->normalize($calendarRole === 'holiday' ? 'time_off' : ($fields['type'] ?? $previous['kind'] ?? $hidden['kimai_kind'] ?? ($holiday ? 'time_off' : (($matches !== [] || $shiftHint) ? 'shift' : ($plainEvents ? 'event' : 'shift')))))) {
            'helyettesites', 'shift' => 'shift', 'szabadsag', 'time off' => 'time_off', 'altalanos esemeny', 'event' => 'event',
            default => throw new \InvalidArgumentException('Unknown event type'),
        };
        if ($matches === [] && !isset($fields['workplace']) && isset($hidden['kimai_workplace'])) {
            foreach ($places as $place) {
                if ($this->normalize($place['name']) === $this->normalize($hidden['kimai_workplace'])) {
                    $matches[$place['id']] = $place;
                }
            }
        }
        if ($kind === 'shift' && !isset($fields['workplace']) && $previous !== []) {
            $place = current(array_filter($places, fn ($p) => $p['id'] === ($previous['workplace'] ?? null)));
            // A clear new title match can change workplace; an unrecognized renamed title keeps the saved link.
            if ($matches === [] && $place !== false) {
                $matches[$place['id']] = $place;
            }
        }
        if ($previous === [] && $matches === [] && $fields === [] && !$shiftHint && !$holiday && !$plainEvents && $calendarRole !== 'holiday' && !isset($hidden['kimai_kind'])) {
            return null;
        }
        if ($kind === 'shift' && \count($matches) !== 1) {
            throw new \InvalidArgumentException('Select exactly one known workplace');
        }
        if ($previous !== [] && $kind !== $previous['kind']) {
            throw new \InvalidArgumentException('Changing the type of a linked event requires review');
        }
        $allDay = isset($event['start']['date']);
        if ($kind === 'shift' && $allDay) {
            throw new \InvalidArgumentException('A shift requires start and end times');
        }
        $start = $event['start'][$allDay ? 'date' : 'dateTime'] ?? '';
        $end = $event['end'][$allDay ? 'date' : 'dateTime'] ?? '';
        if ($start === '' || $end === '') {
            throw new \InvalidArgumentException('Missing event dates');
        }
        $begin = new \DateTimeImmutable($start, new \DateTimeZone($event['start']['timeZone'] ?? 'Europe/Budapest'));
        $finish = new \DateTimeImmutable($end, new \DateTimeZone($event['end']['timeZone'] ?? 'Europe/Budapest'));
        if ($finish <= $begin || ($allDay && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start) || $begin->format('Y-m-d') !== $start))) {
            throw new \InvalidArgumentException('Invalid event dates');
        }
        foreach ([$start => $begin, $end => $finish] as $raw => $parsed) {
            if ($parsed->format($allDay ? 'Y-m-d' : 'Y-m-d\\TH:i') !== substr($raw, 0, $allDay ? 10 : 16)) {
                throw new \InvalidArgumentException('Invalid or nonexistent local event date');
            }
        }
        $note = trim(str_replace($block[0] ?? '', '', $description));
        $result = ['kind' => $kind, 'begin' => $allDay ? $start : $begin->format('U'), 'end' => $allDay ? $end : $finish->format('U'), 'all_day' => $allDay, 'note' => $note];
        if ($kind === 'shift') {
            $place = current($matches);
            if ($place === false) {
                throw new \InvalidArgumentException('Missing workplace');
            }
            $accounting = $this->accountingNote($note);
            $note = $accounting['note'];
            $result['note'] = $note;
            $categoryText = $fields['category'] ?? $accounting['category'];
            if ($categoryText === null) {
                $cats = array_values(array_filter(['EFO', 'Számla'], fn ($c) => str_contains($text, ' ' . $this->normalize($c) . ' ')));
                if (\count($cats) > 1) {
                    throw new \InvalidArgumentException('Conflicting accounting categories');
                }
                $categoryText = $cats[0] ?? $previous['category'] ?? $hidden['kimai_category'] ?? (\is_string($place['category']) ? $place['category'] : '');
            }
            $category = match ($this->normalize($categoryText)) { 'efo' => 'EFO', 'szamla' => 'Számla', default => throw new \InvalidArgumentException('Unknown accounting category') };
            $plainRate = null;
            $rateInput = ($previous === [] || $note !== $previous['note']) ? (($event['summary'] ?? '') . ' ' . $note) : ($event['summary'] ?? '');
            $ratePattern = '/(?<![\p{L}\p{N}.,])([+-]?\d+(?:[ \x{00a0}]\d{3})*(?:[.,]\d+)?)\s*(?:Ft|HUF)\s*\/\s*(?:óra|ora|h)\b/iu';
            preg_match_all($ratePattern, $rateInput, $rates);
            if ($rates[1] !== []) {
                $values = array_values(array_unique(array_map(fn ($v) => str_replace([' ', "\u{00a0}", ','], ['', '', '.'], $v), $rates[1])));
                if (\count($values) !== 1) {
                    throw new \InvalidArgumentException('Conflicting hourly rates');
                }
                $plainRate = $values[0];
                if ($previous !== [] && preg_match($ratePattern, $event['summary'] ?? '') !== 1) {
                    preg_match_all($ratePattern, $previous['note'], $oldRates);
                    $oldValues = array_values(array_unique(array_map(fn ($v) => str_replace([' ', "\u{00a0}", ','], ['', '', '.'], $v), $oldRates[1])));
                    if ($oldValues === $values) {
                        $plainRate = null;
                    }
                }
            }
            $rate = $fields['rate'] ?? $accounting['rate'] ?? $plainRate ?? $previous['rate'] ?? $hidden['kimai_rate'] ?? $place['rate'];
            if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate < 0 || floor((float) $rate) !== (float) $rate) {
                throw new \InvalidArgumentException('Hourly rate must be a nonnegative whole HUF amount');
            }
            $result += ['workplace' => (int) $place['id'], 'category' => $category, 'rate' => (int) $rate, 'cancelled' => isset($fields['cancelled']) ? \in_array($this->normalize($fields['cancelled']), ['1', 'true', 'igen', 'yes'], true) : ($previous['cancelled'] ?? (($hidden['kimai_cancelled'] ?? '0') === '1'))];
            if (mb_strlen($note) > 2000) {
                throw new \InvalidArgumentException('Shift note exceeds 2000 characters');
            }
        } else {
            $title = trim((string) ($event['summary'] ?? ''));
            $color = $fields['color'] ?? $previous['color'] ?? $hidden['kimai_color'] ?? ($kind === 'time_off' ? '#ae3ec9' : '#0ca678');
            if ($title === '' || mb_strlen($title) > 150 || mb_strlen($note) > 200 || !preg_match('/^#[0-9a-f]{6}$/iD', $color)) {
                throw new \InvalidArgumentException('Invalid event name, color or note');
            }
            $result += ['title' => $title, 'color' => $color];
        }

        return $result;
    }

    /** Serializes $value as workplace-only title, category/hourly-rate note and hidden identity metadata, preserving unrelated fields via PATCH; $placeName is the displayed workplace and $linkId is private identity.
     * @param Value $value
     * @return GoogleEvent
     */
    public function outgoing(array $value, string $placeName, string $linkId): array
    {
        $shift = $value['kind'] === 'shift';
        $metadata = ['kimai_link' => $linkId, 'kimai_schema' => '2', 'kimai_kind' => $value['kind']];
        if ($shift) {
            $metadata += ['kimai_workplace' => $placeName, 'kimai_category' => $value['category'] ?? '', 'kimai_rate' => (string) ($value['rate'] ?? 0), 'kimai_cancelled' => ($value['cancelled'] ?? false) ? '1' : '0'];
        } else {
            $metadata['kimai_color'] = $value['color'] ?? '#0ca678';
        }
        $allDay = $value['all_day'];
        $date = fn ($v) => $allDay ? ['date' => $v] : ['dateTime' => (new \DateTimeImmutable('@' . $v))->setTimezone(new \DateTimeZone('Europe/Budapest'))->format(\DateTimeInterface::ATOM), 'timeZone' => 'Europe/Budapest'];

        $description = $shift ? ($value['category'] ?? '') . ', ' . ($value['rate'] ?? 0) . ($value['note'] !== '' ? "\n\n" . $value['note'] : '') : $value['note'];

        return ['summary' => $shift ? (($value['cancelled'] ?? false) ? '[Törölt] ' : '') . $placeName : ($value['title'] ?? ''), 'start' => $date($value['begin']), 'end' => $date($value['end']), 'description' => $description, 'extendedProperties' => ['private' => $metadata]];
    }

    /** Reads an optional first-line EFO/Számla hourly-rate header from $note; the returned note preserves remaining free text.
     * @return array{category:?string,rate:?string,note:string}
     */
    private function accountingNote(string $note): array
    {
        $lines = preg_split('/\R/u', $note, 2) ?: [];
        $first = trim($lines[0] ?? '');
        if (preg_match('/^(EFO|Sz[aá]mla)\b[ \t]*(?:[.:;,|][ \t]*)?(.*)$/iu', $first, $header) !== 1) {
            return ['category' => null, 'rate' => null, 'note' => $note];
        }
        $rate = trim($header[2]);
        if ($rate !== '' && preg_match('/^([+-]?\d+(?:[ \x{00a0}]\d{3})*(?:[.,]\d+)?)(?:\s*(?:Ft|HUF)(?:\s*\/\s*(?:óra|ora|h))?)?$/iu', $rate, $amount) !== 1) {
            throw new \InvalidArgumentException('Invalid category/hourly-rate note');
        }

        return ['category' => $header[1], 'rate' => $rate === '' ? null : str_replace([' ', "\u{00a0}", ','], ['', '', '.'], $amount[1]), 'note' => trim($lines[1] ?? '')];
    }

    /** Clears obsolete date fields when PATCH changes between whole-day and timed $payload; $previous is the last Google event.
     * @param GoogleEvent $payload
     * @param GoogleEvent $previous
     * @return array<string, mixed>
     */
    public function patchDates(array $payload, array $previous): array
    {
        /** @var array<string, mixed> $body */
        $body = $payload;
        if (isset($previous['start']['date']) && isset($payload['start']['dateTime'])) {
            foreach (['start', 'end'] as $field) {
                $body[$field] = ($payload[$field] ?? []) + ['date' => null];
            }
        } elseif (isset($previous['start']['dateTime']) && isset($payload['start']['date'])) {
            foreach (['start', 'end'] as $field) {
                $body[$field] = ($payload[$field] ?? []) + ['dateTime' => null, 'timeZone' => null];
            }
        }

        return $body;
    }

    /** Merges $local/$remote against $base; returns merged data and names of fields changed incompatibly.
     * @param Value $base
     * @param Value $local
     * @param Value $remote
     * @return array{value:Value,conflicts:list<string>}
     */
    public function merge(array $base, array $local, array $remote): array
    {
        $merged = $local;
        $conflicts = [];
        foreach ($remote as $key => $value) {
            $old = $base[$key] ?? null;
            if ($value !== $old && ($local[$key] ?? null) !== $old && ($local[$key] ?? null) !== $value) {
                $conflicts[] = $key;
            } elseif ($value !== $old) {
                $merged[$key] = $value;
            }
        }
        // Date components form one interval: separate edits must not combine into an invalid range.
        if (($merged['end'] ?? '') <= ($merged['begin'] ?? '') || (($local['all_day'] ?? false) !== ($base['all_day'] ?? false) && $remote !== $base && $local !== $remote)) {
            $conflicts[] = 'dates';
        }

        return ['value' => $merged, 'conflicts' => array_values(array_unique($conflicts))];
    }
}

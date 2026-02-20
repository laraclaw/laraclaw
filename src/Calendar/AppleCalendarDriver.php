<?php

namespace LaraClaw\Calendar;

use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Calendar\DTOs\CalendarEvent;
use DateTimeImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class AppleCalendarDriver implements CalendarDriver
{
    public function __construct(
        private string $server,
        private string $username,
        private string $password,
        private string $calendar,
    ) {}

    public function list(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $baseUrl = $this->resolveCalendarUrl();

        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag/>
    <c:calendar-data/>
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="{START}" end="{END}"/>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>
XML;

        $xml = str_replace(
            ['{START}', '{END}'],
            [$start->format('Ymd\THis\Z'), $end->format('Ymd\THis\Z')],
            $xml,
        );

        $response = $this->http()->send('REPORT', $baseUrl, [
            'headers' => ['Content-Type' => 'application/xml', 'Depth' => '1'],
            'body' => $xml,
        ]);

        return $this->parseMultiStatus($response->body());
    }

    public function create(CalendarEvent $event): string
    {
        $baseUrl = $this->resolveCalendarUrl();
        $uid = Str::uuid()->toString();

        $vcalendar = new VCalendar;
        $vevent = $vcalendar->add('VEVENT', [
            'SUMMARY' => $event->title,
            'DTSTART' => $event->start,
            'DTEND' => $event->end,
            'UID' => $uid,
        ]);

        if ($event->description !== null) {
            $vevent->add('DESCRIPTION', $event->description);
        }

        if ($event->location !== null) {
            $vevent->add('LOCATION', $event->location);
        }

        foreach ($event->attendees ?? [] as $email) {
            $vevent->add('ATTENDEE', "mailto:{$email}", ['RSVP' => 'TRUE']);
        }

        $this->http()->send('PUT', "{$baseUrl}/{$uid}.ics", [
            'headers' => ['Content-Type' => 'text/calendar'],
            'body' => $vcalendar->serialize(),
        ]);

        return $uid;
    }

    public function update(string $id, CalendarEvent $event): void
    {
        $baseUrl = $this->resolveCalendarUrl();
        $url = "{$baseUrl}/{$id}.ics";

        $response = $this->http()->send('GET', $url);
        $vcalendar = Reader::read($response->body());
        $vevent = $vcalendar->VEVENT;

        if ($event->title !== null) {
            $vevent->SUMMARY = $event->title;
        }

        if ($event->start !== null) {
            $vevent->DTSTART = $event->start;
        }

        if ($event->end !== null) {
            $vevent->DTEND = $event->end;
        }

        if ($event->description !== null) {
            $vevent->DESCRIPTION = $event->description;
        }

        if ($event->location !== null) {
            $vevent->LOCATION = $event->location;
        }

        if ($event->attendees !== null) {
            unset($vevent->ATTENDEE);
            foreach ($event->attendees as $email) {
                $vevent->add('ATTENDEE', "mailto:{$email}", ['RSVP' => 'TRUE']);
            }
        }

        $this->http()->send('PUT', $url, [
            'headers' => ['Content-Type' => 'text/calendar'],
            'body' => $vcalendar->serialize(),
        ]);
    }

    public function delete(string $id): void
    {
        $baseUrl = $this->resolveCalendarUrl();

        $this->http()->send('DELETE', "{$baseUrl}/{$id}.ics");
    }

    private function resolveCalendarUrl(): string
    {
        return Cache::remember(
            "caldav:calendar_url:{$this->username}:{$this->calendar}",
            3600,
            function () {
                $response = $this->http()->send('PROPFIND', $this->server, [
                    'headers' => ['Content-Type' => 'application/xml', 'Depth' => '0'],
                    'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>',
                ]);
                $principal = $this->extractHref($response->body(), 'current-user-principal');

                $response = $this->http()->send('PROPFIND', $this->server.$principal, [
                    'headers' => ['Content-Type' => 'application/xml', 'Depth' => '0'],
                    'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/></d:prop></d:propfind>',
                ]);
                $homeSet = $this->extractHref($response->body(), 'calendar-home-set');

                $response = $this->http()->send('PROPFIND', $this->server.$homeSet, [
                    'headers' => ['Content-Type' => 'application/xml', 'Depth' => '1'],
                    'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>',
                ]);

                return rtrim($this->server.$this->findCalendarHref($response->body(), $this->calendar), '/');
            },
        );
    }

    private function extractHref(string $xml, string $property): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $nodes = $xpath->query("//d:{$property}/d:href");

        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query("//c:{$property}/d:href");
        }

        if ($nodes === false || $nodes->length === 0) {
            throw new RuntimeException("Could not find {$property} in CalDAV response.");
        }

        return $nodes->item(0)->textContent;
    }

    private function findCalendarHref(string $xml, string $name): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('d', 'DAV:');

        $responses = $xpath->query('//d:response');

        foreach ($responses as $response) {
            $displayName = $xpath->query('.//d:displayname', $response);
            if ($displayName !== false && $displayName->length > 0 && $displayName->item(0)->textContent === $name) {
                $href = $xpath->query('.//d:href', $response);
                if ($href !== false && $href->length > 0) {
                    return $href->item(0)->textContent;
                }
            }
        }

        throw new RuntimeException("Calendar '{$name}' not found on CalDAV server.");
    }

    private function parseMultiStatus(string $xml): array
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $events = [];
        $calendarDataNodes = $xpath->query('//c:calendar-data');

        if ($calendarDataNodes === false) {
            return $events;
        }

        foreach ($calendarDataNodes as $node) {
            $ical = $node->textContent;
            if (empty($ical)) {
                continue;
            }

            $vcalendar = Reader::read($ical);
            if (! isset($vcalendar->VEVENT)) {
                continue;
            }

            $vevent = $vcalendar->VEVENT;
            $attendees = [];
            if (isset($vevent->ATTENDEE)) {
                foreach ($vevent->ATTENDEE as $attendee) {
                    $email = str_replace('mailto:', '', (string) $attendee->getValue());
                    if ($email !== '') {
                        $attendees[] = $email;
                    }
                }
            }

            $events[] = new CalendarEvent(
                title: (string) $vevent->SUMMARY,
                start: new DateTimeImmutable($vevent->DTSTART->getDateTime()->format('c')),
                end: new DateTimeImmutable($vevent->DTEND->getDateTime()->format('c')),
                description: isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                location: isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                id: (string) $vevent->UID,
                attendees: $attendees,
            );
        }

        return $events;
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->username, $this->password);
    }
}

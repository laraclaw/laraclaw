<?php

namespace LaraClaw\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\DTOs\CalendarEvent;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use SimpleXMLElement;

class AppleCalendarDriver implements CalendarDriver
{
    /**
     * Create a new AppleCalendarDriver instance.
     */
    public function __construct(
        private readonly string $server,
        private readonly string $username,
        private readonly string $password,
        private readonly string $calendar,
    ) {}

    /**
     * List all events within the given date range via a CalDAV REPORT request.
     *
     * @return CalendarEvent[]
     */
    public function list(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $response = $this->http()->send('REPORT', $this->resolveCalendarUrl(), [
            'headers' => ['Content-Type' => 'application/xml', 'Depth' => '1'],
            'body' => <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
                  <d:prop><d:getetag/><c:calendar-data/></d:prop>
                  <c:filter>
                    <c:comp-filter name="VCALENDAR">
                      <c:comp-filter name="VEVENT">
                        <c:time-range start="{$start->format('Ymd\THis\Z')}" end="{$end->format('Ymd\THis\Z')}"/>
                      </c:comp-filter>
                    </c:comp-filter>
                  </c:filter>
                </c:calendar-query>
                XML,
        ]);

        return $this->parseMultiStatus($response->body());
    }

    /**
     * Create a new iCalendar event via CalDAV PUT and return its UID.
     */
    public function create(CalendarEvent $event): string
    {
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

        $this->http()->send('PUT', "{$this->resolveCalendarUrl()}/{$uid}.ics", [
            'headers' => ['Content-Type' => 'text/calendar'],
            'body' => $vcalendar->serialize(),
        ]);

        return $uid;
    }

    /**
     * Fetch the existing .ics file, patch the changed fields, and PUT it back.
     */
    public function update(string $id, CalendarEvent $event): void
    {
        $url = "{$this->resolveCalendarUrl()}/{$id}.ics";
        $vcalendar = Reader::read($this->http()->send('GET', $url)->body());
        $vevent = $vcalendar->VEVENT;

        if ($event->title !== null) {
            $vevent->SUMMARY = $event->title;
        }

        if ($event->start instanceof DateTimeImmutable) {
            $vevent->DTSTART = $event->start;
        }

        if ($event->end instanceof DateTimeImmutable) {
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

    /**
     * Delete the event's .ics file from the CalDAV server.
     */
    public function delete(string $id): void
    {
        $this->http()->send('DELETE', "{$this->resolveCalendarUrl()}/{$id}.ics");
    }

    /**
     * Resolve and cache the full CalDAV URL for the configured calendar.
     */
    private function resolveCalendarUrl(): string
    {
        return Cache::remember("caldav:calendar_url:{$this->username}:{$this->calendar}", 3600, function (): string {
            $principalUrl = $this->server . $this->resolvePrincipal();
            $homeSetUrl = $this->server . $this->resolveCalendarHomeSet($principalUrl);

            return rtrim($this->server . $this->calendarHref($homeSetUrl), '/');
        });
    }

    /**
     * Ask the CalDAV server for the principal href of the authenticated user.
     */
    private function resolvePrincipal(): string
    {
        return $this->xpath(
            $this->propfind($this->server, '<d:current-user-principal/>')->body(),
            '//d:current-user-principal/d:href',
        );
    }

    /**
     * Fetch the calendar home set href for the given principal URL.
     */
    private function resolveCalendarHomeSet(string $principalUrl): string
    {
        return $this->xpath(
            $this->propfind($principalUrl, '<c:calendar-home-set xmlns:c="urn:ietf:params:xml:ns:caldav"/>')->body(),
            '//c:calendar-home-set/d:href',
        );
    }

    /**
     * Send a CalDAV PROPFIND request and return the response.
     */
    private function propfind(string $url, string $prop, int $depth = 0): Response
    {
        return $this->http()->send('PROPFIND', $url, [
            'headers' => ['Content-Type' => 'application/xml', 'Depth' => (string) $depth],
            'body' => "<?xml version=\"1.0\"?><d:propfind xmlns:d=\"DAV:\"><d:prop>{$prop}</d:prop></d:propfind>",
        ]);
    }

    /**
     * Find the href of the named calendar within the calendar home set.
     *
     * @throws RuntimeException
     */
    private function calendarHref(string $url): string
    {
        $xml = $this->loadXml($this->propfind($url, '<d:displayname/>', 1)->body());

        foreach ($xml->xpath('//d:response') as $response) {
            if ((string) $response->xpath('.//d:displayname')[0] === $this->calendar) {
                return (string) $response->xpath('.//d:href')[0];
            }
        }

        throw new RuntimeException("Calendar '{$this->calendar}' not found on CalDAV server.");
    }

    /**
     * Parse a CalDAV multistatus response body into an array of CalendarEvent DTOs.
     *
     * @return CalendarEvent[]
     */
    private function parseMultiStatus(string $body): array
    {
        $events = [];

        foreach ($this->loadXml($body)->xpath('//c:calendar-data') as $data) {
            $vcalendar = Reader::read((string) $data);

            if (! isset($vcalendar->VEVENT)) {
                continue;
            }

            $vevent = $vcalendar->VEVENT;
            $attendees = [];

            foreach ($vevent->ATTENDEE ?? [] as $attendee) {
                $email = str_replace('mailto:', '', (string) $attendee->getValue());
                if ($email !== '') {
                    $attendees[] = $email;
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

    /**
     * Parse an XML string and register the DAV and CalDAV namespaces for XPath.
     */
    private function loadXml(string $xml): SimpleXMLElement
    {
        $el = simplexml_load_string($xml);
        $el->registerXPathNamespace('d', 'DAV:');
        $el->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        return $el;
    }

    /**
     * Evaluate an XPath expression against an XML string and return the first result.
     *
     * @throws RuntimeException
     */
    private function xpath(string $xml, string $path): string
    {
        $results = $this->loadXml($xml)->xpath($path);

        if (empty($results)) {
            throw new RuntimeException("XPath '{$path}' returned no results in CalDAV response.");
        }

        return (string) $results[0];
    }

    /**
     * Build an HTTP client already configured with Basic Auth credentials.
     */
    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->username, $this->password);
    }
}

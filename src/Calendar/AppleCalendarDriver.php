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
use LaraClaw\Calendar\DTOs\CalendarEvent;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use SimpleXMLElement;

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

    public function update(string $id, CalendarEvent $event): void
    {
        $url = "{$this->resolveCalendarUrl()}/{$id}.ics";
        $vcalendar = Reader::read($this->http()->send('GET', $url)->body());
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
        $this->http()->send('DELETE', "{$this->resolveCalendarUrl()}/{$id}.ics");
    }

    private function resolveCalendarUrl(): string
    {
        return Cache::remember("caldav:calendar_url:{$this->username}:{$this->calendar}", 3600, function () {
            $principal = $this->xpath(
                $this->propfind($this->server, '<d:current-user-principal/>')->body(),
                '//d:current-user-principal/d:href',
            );

            $homeSet = $this->xpath(
                $this->propfind($this->server . $principal, '<c:calendar-home-set xmlns:c="urn:ietf:params:xml:ns:caldav"/>')->body(),
                '//c:calendar-home-set/d:href',
            );

            return rtrim($this->server . $this->calendarHref($this->server . $homeSet), '/');
        });
    }

    private function propfind(string $url, string $prop, int $depth = 0): Response
    {
        return $this->http()->send('PROPFIND', $url, [
            'headers' => ['Content-Type' => 'application/xml', 'Depth' => (string) $depth],
            'body' => "<?xml version=\"1.0\"?><d:propfind xmlns:d=\"DAV:\"><d:prop>{$prop}</d:prop></d:propfind>",
        ]);
    }

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

    private function loadXml(string $xml): SimpleXMLElement
    {
        $el = simplexml_load_string($xml);
        $el->registerXPathNamespace('d', 'DAV:');
        $el->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        return $el;
    }

    private function xpath(string $xml, string $path): string
    {
        $results = $this->loadXml($xml)->xpath($path);

        if (empty($results)) {
            throw new RuntimeException("XPath '{$path}' returned no results in CalDAV response.");
        }

        return (string) $results[0];
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->username, $this->password);
    }
}

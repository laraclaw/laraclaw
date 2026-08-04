<?php

namespace Laraclaw\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Laraclaw\Tests\TestCase;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Guards the ordering between the provider and the config, not just the mapping.
 *
 * The SMTP settings are applied in defineEnvironment(), which Testbench runs after the
 * provider has already been registered. Reading the config during register() therefore
 * sees an empty connector and leaves the mailer on the default transport, which is how
 * a broken mailer once passed for a working one.
 */
class EmailConnectorConfigTest extends TestCase
{
    /**
     * @test
     */
    public function it_copies_the_smtp_settings_into_the_mail_config(): void
    {
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('tls', config('mail.mailers.smtp.encryption'));
        $this->assertSame('bot@example.com', config('mail.mailers.smtp.username'));
        $this->assertSame('bot@example.com', config('mail.from.address'));
        $this->assertSame('Laraclaw', config('mail.from.name'));
    }

    /**
     * @test
     */
    public function the_resolved_transport_is_a_real_smtp_transport(): void
    {
        $this->assertInstanceOf(EsmtpTransport::class, Mail::mailer()->getSymfonyTransport());
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laraclaw.connectors.email.enabled', true);
        $app['config']->set('laraclaw.connectors.email.smtp.host', 'smtp.example.com');
        $app['config']->set('laraclaw.connectors.email.smtp.port', 2525);
        $app['config']->set('laraclaw.connectors.email.smtp.encryption', 'tls');
        $app['config']->set('laraclaw.connectors.email.smtp.username', 'bot@example.com');
        $app['config']->set('laraclaw.connectors.email.smtp.password', 'secret');
        $app['config']->set('laraclaw.connectors.email.smtp.from_address', 'bot@example.com');
        $app['config']->set('laraclaw.connectors.email.smtp.from_name', 'Laraclaw');
    }
}

<?php

namespace NoriaLabs\Mail\Tests;

use NoriaLabs\Mail\Providers\MailServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MailServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('noria-mail.url', 'https://mail.noria.test');
        $app['config']->set('noria-mail.key', 'nm_test_abcdefghijklmnopqrstuvwx');
        $app['config']->set('noria-mail.webhook_secret', 'whsec_testsecret');

        $app['config']->set('mail.default', 'noria');
        $app['config']->set('mail.mailers.noria', ['transport' => 'noria']);
        $app['config']->set('mail.from', ['address' => 'hello@example.test', 'name' => 'Noria']);
    }
}

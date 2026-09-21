<?php

namespace NoriaLabs\Mail\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use NoriaLabs\Mail\MailClient;
use NoriaLabs\Mail\MailTransport;
use NoriaLabs\Mail\WebhookVerifier;

class MailServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/noria-mail.php', 'noria-mail');

        $this->app->singleton(MailClient::class, function (): MailClient {
            /** @var array{key: string, url: string, timeout: int, retries: int} $config */
            $config = $this->app->make('config')->get('noria-mail');

            return new MailClient(
                $this->app->make(Factory::class),
                $config['key'],
                $config['url'],
                $config['timeout'],
                $config['retries'],
            );
        });

        $this->app->singleton(WebhookVerifier::class, function (): WebhookVerifier {
            /** @var array{webhook_secret: string, webhook_tolerance: int} $config */
            $config = $this->app->make('config')->get('noria-mail');

            return new WebhookVerifier($config['webhook_secret'], $config['webhook_tolerance']);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/noria-mail.php' => $this->app->configPath('noria-mail.php'),
        ], 'noria-mail-config');

        Mail::extend('noria', function (array $config): MailTransport {
            $client = isset($config['key']) && is_string($config['key']) && $config['key'] !== ''
                ? new MailClient(
                    $this->app->make(Factory::class),
                    $config['key'],
                    is_string($config['url'] ?? null) ? $config['url'] : 'http://localhost:4800',
                )
                : $this->app->make(MailClient::class);

            return new MailTransport($client, (bool) ($config['fail_on_suppressed'] ?? false));
        });
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [MailClient::class, WebhookVerifier::class];
    }
}

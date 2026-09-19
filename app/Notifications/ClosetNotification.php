<?php

namespace App\Notifications;

use App\Notifications\Channels\AppDatabaseChannel;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared shape for every notification the platform sends.
 *
 * A subclass says what happened (`type`), what the in-app entry should carry
 * (`payload`) and where it points. All channels are driven from that one
 * description, so the bell and the phone alert cannot tell different stories.
 *
 * Deliberately not `ShouldQueue`, and the `Queueable` trait BizyFarmers carried
 * is left off. There is no worker on the shared host, so a queued notification
 * would sit in a table nothing drains -- silently, which is the worst way for
 * this to fail. Sending happens in the request; it is one or two short HTTPS
 * calls. See CLAUDE.md section 5.
 */
abstract class ClosetNotification extends Notification
{
    /** Machine-readable event name, stored on the notification row. */
    abstract public function type(): string;

    /** Data the in-app entry renders from. Must include a `message`. */
    abstract public function payload(object $notifiable): array;

    /** The heading, used in-app, on the lock screen, and as a subject line. */
    abstract public function subject(object $notifiable): string;

    /** Where the notification points, as a path in the React app. */
    public function url(object $notifiable): ?string
    {
        return null;
    }

    /**
     * In-app always; the channels that leave the app where there is an appetite.
     *
     * The in-app entry is deliberately not optional: it is the record of what
     * happened to somebody's cloth and money, and a settings toggle should not
     * be able to erase that. Push is the noisy one, so push is what a person
     * can turn down -- except for anything about the account itself, which they
     * have to be told regardless.
     *
     * Mail rides on the same appetite rather than a second set of switches.
     * Most people here have no address at all, so it is guarded twice and is a
     * courtesy for the few who do -- never the channel a feature depends on.
     */
    public function via(object $notifiable): array
    {
        $wanted = ! method_exists($notifiable, 'wantsPush')
            || $notifiable->wantsPush($this->type());

        return array_filter([
            AppDatabaseChannel::class,
            $wanted ? WebPushChannel::class : null,
            $wanted && filled($notifiable->email) ? 'mail' : null,
        ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'payload' => array_merge(
                ['title' => $this->subject($notifiable), 'url' => $this->url($notifiable)],
                $this->payload($notifiable),
            ),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payload = $this->payload($notifiable);
        $url = $this->url($notifiable);

        $mail = (new MailMessage)
            ->subject($this->subject($notifiable))
            ->greeting('Hi '.$notifiable->name.',')
            ->line($payload['message']);

        foreach ($payload['detail'] ?? [] as $line) {
            $mail->line($line);
        }

        if ($url) {
            $mail->action(
                $payload['action'] ?? "Open Rachel's Closet",
                rtrim((string) config('app.frontend_url'), '/').$url,
            );
        }

        return $mail->salutation("— Rachel's Closet");
    }
}

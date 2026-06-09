<?php

namespace App\Notifications\Imports;

use App\Models\Import;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ImportCompletedNotification extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public Import $import)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $results = $this->import->results ?? [];

        $message = (new MailMessage)
            ->subject(__('Your :type import is complete', ['type' => $this->import->type->label()]))
            ->line(__('Your :type import for :team has finished.', [
                'type' => strtolower($this->import->type->label()),
                'team' => $this->import->team->name,
            ]));

        if (isset($results['created'])) {
            $message->line(__(':created of :total records were imported.', [
                'created' => $results['created'],
                'total' => $results['total'] ?? $results['created'],
            ]));
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'import_id' => $this->import->id,
            'type' => $this->import->type->value,
            'results' => $this->import->results,
        ];
    }
}

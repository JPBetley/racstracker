<?php

namespace App\Notifications\Imports;

use App\Models\Import;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ImportFailedNotification extends Notification
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
        return (new MailMessage)
            ->error()
            ->subject(__('Your :type import failed', ['type' => $this->import->type->label()]))
            ->line(__('Your :type import for :team could not be completed.', [
                'type' => strtolower($this->import->type->label()),
                'team' => $this->import->team->name,
            ]))
            ->line(__('Error: :error', ['error' => $this->import->error]));
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
            'error' => $this->import->error,
        ];
    }
}

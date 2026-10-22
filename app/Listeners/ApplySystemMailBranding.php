<?php

namespace App\Listeners;

use App\Services\SystemSettingsService;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

class ApplySystemMailBranding
{
    public function handle(MessageSending $event): void
    {
        $sender = $event->message->getFrom()[0] ?? null;
        if ($sender) {
            $event->message->from(new Address($sender->getAddress(), app(SystemSettingsService::class)->get('branding.name_ar')));
        }
    }
}

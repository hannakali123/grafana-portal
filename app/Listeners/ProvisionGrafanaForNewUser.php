<?php

namespace App\Listeners;

use App\Services\GrafanaApi;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

class ProvisionGrafanaForNewUser
{
    public function __construct(private readonly GrafanaApi $grafana) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        if ($user->grafana_token) {
            return;
        }

        try {

            $meta = $this->grafana->bootstrapFor($user);

            $user->update([
                'grafana_token'  => $meta['token'],
                'grafana_org_id' => $meta['org_id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Grafana bootstrap failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);



            throw $e;
        }
    }
}

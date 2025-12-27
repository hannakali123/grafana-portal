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

        // idempotent: wenn schon provisioniert, nichts tun
        if ($user->grafana_token) {
            return;
        }

        try {
            // KEINE DB::transaction hier – wegen CREATE DATABASE / TABLE
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

            // Optional: Konsistenz herstellen, wenn Registrierung ohne Grafana
            // nicht erlaubt ist:
            // $user->delete();

            throw $e; // damit du den Fehler weiterhin im Browser/Log siehst
        }
    }
}

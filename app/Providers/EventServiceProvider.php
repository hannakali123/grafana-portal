<?php

namespace App\Providers;

use App\Listeners\ProvisionGrafanaForNewUser;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Registered::class => [
            ProvisionGrafanaForNewUser::class,
        ],
    ];
}

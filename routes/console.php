<?php

use Illuminate\Support\Facades\Schedule;

// Servers drift from the panel's CLI build whenever the panel is updated,
// and the panel itself falls behind new releases; the hourly check raises
// a notice for each until it is dealt with.
Schedule::command('mandal:check-updates')->hourly()->withoutOverlapping();

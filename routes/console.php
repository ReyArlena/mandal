<?php

use Illuminate\Support\Facades\Schedule;

// Servers drift from the panel's CLI build whenever the panel is updated;
// the hourly check raises a notice for each one until it is updated.
Schedule::command('mandal:check-cli')->hourly()->withoutOverlapping();

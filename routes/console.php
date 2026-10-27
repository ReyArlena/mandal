<?php

use Illuminate\Support\Facades\Schedule;

// Servers drift from the panel's CLI build whenever the panel is updated,
// and the panel itself falls behind new releases; the hourly check raises
// a notice for each until it is dealt with.
Schedule::command('mandal:check-updates')->hourly()->withoutOverlapping();

// Backups run from cron on each server, so the panel polls their status
// and raises a notice for every failed run it has not reported yet.
Schedule::command('mandal:check-backups')->hourly()->withoutOverlapping();

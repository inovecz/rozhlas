<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:play-schedules')->everyMinute();
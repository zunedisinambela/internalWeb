<?php

/*
|--------------------------------------------------------------------------
| User Monitoring Routes
|--------------------------------------------------------------------------
|
| Deliberately empty. binafy/laravel-user-monitoring ships six routes under
| /user-monitoring — three Blade dashboards and three DELETE endpoints — and
| its LaravelUserMonitoringRouteServiceProvider registers them with the `web`
| middleware group only: no `auth`, no gate, and the controllers never call
| authorize(). Anonymous visitors could read every IP, page and login time,
| and delete the records.
|
| That provider loads this file instead of the vendor one whenever it exists
| (see config `user-monitoring.config.routes.file_path`), so keeping it empty
| is what removes those routes. Do not delete this file — the vendor routes
| come straight back if it disappears.
|
| The data is browsable inside the panel instead, at /admin/visits and
| /admin/authentications, behind the same role check as the rest of /admin
| and with no delete path at all.
|
*/

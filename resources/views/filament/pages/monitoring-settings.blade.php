{{--
    Everything on this page is built as a schema in MonitoringSettings::content()
    — the form, its save button and the scheduler status callout. This file only
    wraps it, the same way Filament's own edit-profile page does.
--}}
<x-filament-panels::page>
    {{ $this->content }}
</x-filament-panels::page>

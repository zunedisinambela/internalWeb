<?php

namespace App\Filament\Resources\Concerns;

/**
 * Sends Simpan on an edit screen back to the table it was opened from.
 *
 * Filament's own default is to stay put: EditRecord::getRedirectUrl() returns
 * null whenever the signed-in user may still access the page it is already on,
 * and only leaves when that authorization has just been lost — to the view page
 * if there is one, to the list if there is not. So the ordinary successful save
 * is the case that never redirects, and the only thing that changes on screen
 * is a notification that fades. On a form long enough to have scrolled, the
 * notification is above the fold and the save reads as though nothing happened.
 *
 * Applied per page rather than through the panel. Filament exposes
 * ->resourceEditPageRedirect('index') in AdminPanelProvider, which is one line
 * against four, and is the wrong line: it is a default, so every resource added
 * afterwards inherits it silently, including ones where staying on the form is
 * right — a screen edited repeatedly against a reference, or one whose relation
 * managers are the reason it was opened. The same argument as
 * readOnlyRelationManagersOnResourceViewPagesByDefault, recorded in CLAUDE.md:
 * override the thing that wants the behaviour, do not move the floor under
 * everything.
 *
 * One consequence to know, because it is invisible from here. No table in this
 * project calls persistFiltersInSession(), so the list is rebuilt from scratch:
 * a filter, a search term and the page number are all gone by the time the row
 * that was just edited comes back into view. Staying on the form used to make
 * browser-back the way home, and browser-back restored all three. Anything that
 * wants them kept has to say so on its own table.
 */
trait ReturnsToListAfterSaving
{
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}

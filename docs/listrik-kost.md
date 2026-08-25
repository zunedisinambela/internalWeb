# Listrik kost

Every room is metered separately, so every room is billed separately. Three screens under one
`Kost` navigation group, and one decision that everything else hangs off.

| Piece | Where |
|-------|-------|
| Room | `App\Models\Room`, `/rooms` (`app/Filament/Resources/Rooms/`) |
| Tariff | `App\Models\ElectricityTariff`, `/electricity-tariffs` |
| Reading | `App\Models\MeterReading`, `/meter-readings` |
| Photos | media collections `MeterReading::PHOTOS_START` / `::PHOTOS_END`, private `local` disk |
| Amounts | `App\Rules\WholeRupiah` on the rate, the same rule the cash book uses |
| Correction | `Resources/MeterReadings/Actions/RefreshRateAction` — the one way a recorded rate moves |

**The rate is copied onto the reading, never joined to.** `meter_readings.rate` is a snapshot of
`electricity_tariffs` taken when the reading is recorded, and it is the load-bearing decision of
the whole feature. A join would make every bill read the *current* rate, so entering a raise in
August would silently reprice July — no row changed, nothing in `activity_log`, and a tenant's
issued bill quietly becoming a different number. Copying it means a tariff change applies to
what is recorded after it, which is what raising a tariff actually means.
`test_a_later_tariff_does_not_change_a_recorded_reading` is the assertion that keeps it;
without it the feature still passes every other test while doing the wrong thing.

**The rate field is hidden on both form screens.** It is not a decision taken at the meter — it is
set once on the tariff screen and copied — so asking for it while recording only invites a typo
into the one figure the tenant is billed by. `MeterReadingForm::showsRate()` is the single rule,
read by the field's `->visible()` and by the section heading, which drops "dan tarif" when the
field is not there.

**The view screen does not show it either.** `MeterReadingInfolist`'s `Tagihan` section carries
the total alone. A rate printed beside the total reads as a sum the reader can recompute, and
the one figure they would reach for to recompute it is today's tariff — the exact
misunderstanding the snapshot exists to prevent. The stored rate is still reachable: it is a
`toggleable` column on the list, hidden by default, and every change to it is on the
`meter_reading` allowlist.

It appears on **one** path: no tariff exists, so there is nothing to copy and `rate` is
`NOT NULL`. Hiding it there would refuse the save with a message naming a field nobody can see.
That is the same escape hatch the warning `Callout` announces, and it is why the field keeps its
`->default()`, its `WholeRupiah` rule and its grouped-input round trip.

Showing the field on the edit screen only — the obvious middle ground — was tried and dropped:
entering a reading is a frequent act and correcting a rate is a rare one, so a field that is
wrong to ask for while recording does not become right to ask for while correcting. `rate` stays
on the `LogsActivity` allowlist regardless; it is the one column here whose value came from
somewhere else, so a fix made from tinker is still audited.

**A rate typed wrong is corrected by a button, not by the field.**
`Resources/MeterReadings/Actions/RefreshRateAction` refills one reading's rate from the tariff
row, on the edit screen only. It exists because the snapshot is not negotiable and yet an honest
mistake — a reading entered before the tariff screen was filled in, or one recorded while the
tariff itself carried a typo — needs a way out that is not tinker. Four properties keep it a
correction rather than the automatic recalculation the snapshot forbids:

| Property | Why |
|----------|-----|
| asked for, never automatic | a raise entered on the tariff screen still cannot reach a recorded reading |
| shows what it would move, before confirming | "nothing changes" and "this bill changes" must not be the same click |
| writes into the open form and **does not save** | Simpan is the user's; the `meter_reading` audit entry then comes from `LogsActivity` on the ordinary path, exactly as a hand correction would |
| hidden when the stored rate already matches | the button's absence answers "is this rate current?" without opening a modal that says no |

**Those four properties are the pattern for any copied figure in this project, and this is now
the only place carrying it.** Oriflame had a `RefreshPricesAction` of the same shape until that
feature stopped copying prices at all (see Oriflame) — so when a second snapshot appears, copy
the properties from here rather than inventing another answer.

**It takes the tariff in force at `end_read_at`, not the newest one.** Tariffs are versioned, so
a July reading corrected in August has two candidate rates, and the newest is the wrong one
— copying August's rate onto a July bill is exactly the repricing the snapshot exists to
prevent, arriving through a button instead of through a join.
`test_the_rate_refresh_takes_the_tariff_in_force_when_the_period_closed` is what keeps it.
The date is read from `$livewire->data['end_read_at']` rather than from the row, so a correction
that also moves the closing moment offers the tariff for the date being saved. A reading that
closed before any tariff took effect has nothing to copy, so the button is simply absent.

The confirmation names both rates **and the bill each produces**, which matters more here than
on a sale: the rate field is hidden, so the `Perhitungan` total is the only thing on screen that
moves when the action fires, and it is what the user checks before Simpan. The tariff's `note`
is user text interpolated into an `HtmlString`, so it goes through `e()`. A tariff note arrives
from a table nobody thinks of as user input, which is exactly why the escape is easy to drop —
see the escaping table under Gotchas.

**`->dehydratedWhenHidden()` is what makes hiding it safe, and its absence is silent.** Filament
does not dehydrate a hidden component: `isDehydrated()` returns false through
`isHiddenAndNotDehydratedWhenHidden()`, and the state path is then *removed* from the payload.
Without the flag the column receives nothing, the save fails on a NOT NULL the form never
mentions, and the snapshot this whole feature rests on is gone.
`test_the_rate_is_copied_even_though_the_field_is_hidden` fills the create form without a rate
and asserts the stored figure, which is the only way that stays caught.

**The edit screen is the other half of that, and the more dangerous one.**
`test_editing_a_reading_does_not_recopy_the_current_tariff` saves an unrelated field on a reading
stored at 1.500 while the tariff screen has moved to 2.000, and asserts the row still reads
1.500. A hidden field that re-copied `currentRate()` on save would reprice an issued bill while
looking like an ordinary edit — the exact failure the snapshot exists to prevent, arriving
through the form instead of through a join.

**Tariffs are versioned, not a settings row.** A single row answers "what is the rate now"; these
rows also answer "what was it in July", which is the question a tenant asks. Raising the price
is a new row, never an edit. `effective_from` is **unique**: two tariffs on one day would make
"which rate is in force" unanswerable and the tiebreak would silently become insertion order.
A row dated ahead is how a raise is scheduled — `ElectricityTariff::current()` ignores it until
the day arrives, which is why the status column is derived from the dates rather than stored.
Nothing has to flip a flag at midnight, which matters because this app runs no scheduler for
anything but retention (see Monitoring) and its absence is silent.

`current()` returns **null** on an empty table and callers have to handle it. Inventing a
default rate would put a made-up number onto a bill. The reading form deals with it by warning
and letting the rate be typed by hand rather than refusing to open: the meter has already been
read by then, and the figure is on a phone screen that will be gone tomorrow.

**Rooms are retired, not deleted.** `meter_readings.room_id` is `restrictOnDelete` — not
`cascade`, which would erase the billing history, and not `nullOnDelete`, because a reading
without a room means nothing. That is different from `user_id`, where an unattributed row is
still a true record. So `rooms.is_active` is the exit, and the rule is enforced twice on
purpose:

- `RoomResource::canDelete()` turns the refusal into a missing button instead of a
  `QueryException`. It lives on the resource, not on the action, because Filament consults the
  resource for the row action *and* for every record inside a bulk delete.
- The foreign key covers tinker, a console command and anything that never asks the resource.
  SQLite enforces it only with the `foreign_keys` pragma on, which Laravel sets by default.

**kWh are whole integers, both figures.** Same reasoning as the rupiah columns under Keuangan:
SQLite has no real `DECIMAL`, and `usage × rate` is what becomes money. Meters here read whole
kWh, so fractions are refused outright rather than rounded silently.

**`usage_kwh` and `total_amount` are derived, never stored.** They are accessors on the model, so
they cannot disagree with the three columns they come from — a stored total would be a fourth
number able to contradict them. The cost is that sorting on them has to be spelled out
(`->sortable(query: …)` with an `orderByRaw`), because there is no column to order by and
letting Filament guess would silently sort on nothing.

Neither is clamped at zero. The form refuses a closing figure below the opening one, so a
negative can only come from a row written outside it — and showing it in red is how that becomes
visible. `max(0, …)` would render the same broken row as a plausible bill of Rp 0.

**A reading is a period with two ends, and each end carries three things**: a figure, the moment
it was read, and its own photographs. `start_kwh` / `start_read_at` / `PHOTOS_START` against
`end_kwh` / `end_read_at` / `PHOTOS_END`. The form lays them out as two sections side by side so
a photograph sits under the number it is evidence for; uploading one against the wrong end takes
a deliberate mistake rather than a careless one.

**Two photo collections, not one holding both.** Which photograph backs which figure is the
whole evidentiary point — a disputed bill is settled by comparing the opening figure against the
photograph taken when the period opened. A single collection could only express that by upload
order, which reordering or deleting one file destroys silently.
`collection_name` on the row is what nothing in the UI can scramble.
`test_a_photo_belongs_to_the_end_it_was_uploaded_against` pins it.

**`end_read_at` is what dates the row**, everywhere: the list sorts on it, the date filter
matches on it, and `previousFor()` orders and scopes by it. A period is placed on the timeline by
where it closes — ordering on `start_read_at` would let a short reading taken inside a long one
come back as the later of the two. It also keeps the prefill from being circular, since
`start_read_at` is what that lookup fills in and so cannot also be what scopes it.

The date filter matches the closing moment **only**, never either end. A period straddling a
month boundary belongs to the month it closed in, which is the month it is billed in; matching
both ends would return one reading under two adjacent filters.

**Both ends of the previous reading are prefilled**, which is what makes them one continuous
meter rather than four unrelated fields: `start_kwh` from the previous `end_kwh`, `start_read_at`
from the previous `end_read_at`. Prefilled, not locked: a replaced meter starts again from zero
and only the person holding the photograph knows that happened.
`MeterReading::previousFor()` is the single query behind it, and `Room::latestReading()`
delegates to it rather than repeating the ordering — `id` is the tiebreak, for the same reason
`CashBook` orders by it.

The moment is prefilled only when there **is** a previous reading. A room being read for the
first time keeps the `now()` default rather than having a required field blanked, which would
read as the form breaking rather than as an empty history.

`previousFor()` takes `$before` and `$excludingId` for the edit screen: without them, reopening
a reading offers that same row as its own predecessor.

**Two refusals, one on each pair.** A closing figure below the opening one (`->gte('start_kwh')`)
is refused with a message naming the replaced-meter case, because a typo and a replaced meter
need different handling and the form cannot tell them apart. A closing moment before the opening
one (`->afterOrEqual('start_read_at')`) is refused because `end_read_at` dates the row — such a
reading would sort into the wrong place forever and be offered as the predecessor of readings
taken before it. `afterOrEqual` rather than `after`: both figures read in one visit is a real
case, and a minute-precision picker could not tell it from a mistake anyway.

**Auditing is split three ways**, the same shape as the cash book and for the same reason — no
single mechanism can see all of it:

| Change | Recorded by |
|--------|-------------|
| `room_id`, `start_kwh`, `start_read_at`, `end_kwh`, `end_read_at`, `rate`, `note` | `LogsActivity`, log name `meter_reading` |
| a room's name, occupant or status | `LogsActivity`, log name `room` |
| a rate set or changed | `LogsActivity`, log name `tariff` |
| a photo removed, from either end | `AppServiceProvider::registerMediaDeletionLogging()`, event `meter_photo_deleted` |
| a photo attached or replaced | **nothing** |

Both photo collections write the **same** event key. Which end lost a photograph is already in
the entry's `collection` property, which the listener writes for every owner — a second event key
would mean remembering two of them to filter for "a meter photograph was removed".

`occupant` is on the room allowlist deliberately: who was in a room when a reading was taken is
exactly what a disputed bill turns on.

**What this feature does not do yet**, each a decision rather than an omission:

- **Nothing reaches the cash book.** A reading does not create a `Transaction`, automatically or
  otherwise. Wiring it up raises questions this does not answer — what happens to the
  transaction when the reading is edited or deleted, and whether a reading means money owed or
  money received. Until those are settled, two independent records are honest and one linked
  pair would be misleading.
- **One rate for every room.** A per-room rate (an AC room costing more) needs a column on
  `rooms` plus a fallback to the global tariff. The snapshot on the reading means adding it
  later changes nothing already recorded.
- **No standing charge and no minimum usage.** `total_amount` is `usage × rate` and nothing
  else. Both are common in kost billing and both would be columns on `electricity_tariffs`, so
  they are cheap to add — but adding them unasked would have put figures on a bill nobody
  specified.
- **No bill to hand the tenant.** The panel shows the total; there is no per-room PDF or
  spreadsheet. `pdf.buku-kas` and `TransactionsExport` are the shapes to copy, and PDF and
  Spreadsheet below record what silently goes wrong in each.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.

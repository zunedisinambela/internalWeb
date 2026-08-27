# Listrik kost

One screen. A period of electricity use is one row: the figure the meter opened at, the figure
it closed at, a photograph behind each, and the price per kWh it is billed at.

| Piece | Where |
|-------|-------|
| Reading | `App\Models\MeterReading`, `/meter-readings` (`app/Filament/Resources/MeterReadings/`) |
| Photos | media collections `MeterReading::PHOTOS_START` / `::PHOTOS_END`, private `local` disk |
| Amounts | `App\Rules\WholeRupiah` on the rate, the same rule the cash book uses |

**It used to be three screens, and the shape it lost is worth knowing.** Rooms and a versioned
`electricity_tariffs` table sat beside this one, because the feature was written for a landlord
metering several rooms and raising the price for all of them at once. It is now written for the
tenant recording their own meter, so both were removed by
`2026_08_27_120000_simplify_kost_to_one_screen`. Two things follow from that history:

- **The migration is destructive and was run knowingly.** Room names, occupants and the history
  of when the price changed are gone. No bill lost its meaning, because of the next point.
- **`meter_readings.rate` was always a copy, never a join** — and that is exactly why the tariff
  table could be dropped without touching a single recorded amount. Every reading already
  carried the price it was billed at.

**The rate lives on the reading.** It is the load-bearing decision of the feature and it
outlived the screen it was originally copied from. A rate held anywhere else — a settings row, a
tariff table joined at display time — would make every bill read at *today's* price, so entering
a raise in August would silently reprice July: no row changed, nothing in `activity_log`, and a
bill that was already issued quietly becoming a different number.
`test_a_later_reading_at_a_new_rate_does_not_change_an_earlier_bill` is the assertion that keeps
it, and `test_editing_a_reading_does_not_pick_up_a_newer_rate` is the more dangerous half — a
form that re-derived the rate on save would reprice an issued bill while looking like an
ordinary edit.

**The rate field is on screen, asked for on every reading.** It was hidden for as long as a
tariff screen supplied it, on the reasoning that a figure not decided at the meter should not be
typed at the meter. With nothing else to supply it, hiding it would mean a NOT NULL column with
no way to fill it. So it is an ordinary visible field — and the correction path collapsed with
it: there used to be a `RefreshRateAction` that refilled a wrong rate from the tariff row, and
the field itself is now that correction.
`test_the_rate_field_is_on_screen_and_required` is what stops it drifting back into being hidden.

**It defaults from the previous reading**, which is `MeterReadingForm::defaultRate()`. A price
that has not moved is one fewer thing to type each month; one that has moved is a field already
on screen waiting to be corrected. Two properties of that default matter:

- **It reads the latest *period*, not the last row written.** `MeterReading::previous()` orders
  by `end_read_at`, so a correction entered out of order still leaves the newest price as the
  one offered next.
- **Null on the first reading, and deliberately not a made-up number.** `rate` is NOT NULL and
  required, so the form asks. A default of `0` or `1.500` would put a figure nobody chose
  straight onto a bill.

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
matches on it, and `previous()` orders and scopes by it. A period is placed on the timeline by
where it closes — ordering on `start_read_at` would let a short reading taken inside a long one
come back as the later of the two. It also keeps the prefill from being circular, since
`start_read_at` is what that lookup fills in and so cannot also be what scopes it.

The date filter matches the closing moment **only**, never either end. A period straddling a
month boundary belongs to the month it closed in, which is the month it is billed in; matching
both ends would return one reading under two adjacent filters.

**Three things are prefilled from the previous reading**, which is what makes them one continuous
meter rather than a pile of unrelated periods: `start_kwh` from the previous `end_kwh`,
`start_read_at` from the previous `end_read_at`, and the rate. `MeterReading::previous()` is the
single query behind all three.

Prefilled, not locked: a replaced meter starts again from zero and only the person holding the
photograph knows that happened. And prefilled only when there **is** a previous reading — a first
reading keeps `0` and `now()` rather than having required fields blanked, which would read as the
form breaking rather than as an empty history.

They arrive through `->default()` on each field rather than through an `afterStateUpdated()`
hook. The hook was needed while a room select was the thing being reacted to; with nothing to
select, a default that resolves once when the form opens is both simpler and one fewer query per
keystroke.

`previous()` takes `$before` and `$excludingId` for the edit screen: without them, reopening a
reading offers that same row as its own predecessor.

**Two refusals, one on each pair.** A closing figure below the opening one (`->gte('start_kwh')`)
is refused with a message naming the replaced-meter case, because a typo and a replaced meter
need different handling and the form cannot tell them apart. A closing moment before the opening
one (`->afterOrEqual('start_read_at')`) is refused because `end_read_at` dates the row — such a
reading would sort into the wrong place forever and be offered as the predecessor of readings
taken before it. `afterOrEqual` rather than `after`: both figures read in one visit is a real
case, and a minute-precision picker could not tell it from a mistake anyway.

**The view screen prints the rate beside the total.** It did not while a tariff screen existed:
back then a rate printed next to the bill invited recomputing it from *today's* tariff, which is
the one number that must never be applied to a closed period. There is no other rate to confuse
it with any more — the row carries the only one — so printing both halves of the multiplication
is what makes the total checkable.

**Auditing is split two ways**, because no single mechanism can see all of it:

| Change | Recorded by |
|--------|-------------|
| `start_kwh`, `start_read_at`, `end_kwh`, `end_read_at`, `rate`, `note` | `LogsActivity`, log name `meter_reading` |
| a photo removed, from either end | `AppServiceProvider::registerMediaDeletionLogging()`, event `meter_photo_deleted` |
| a photo attached or replaced | **nothing** |

Both photo collections write the **same** event key. Which end lost a photograph is already in
the entry's `collection` property, which the listener writes for every owner — a second event key
would mean remembering two of them to filter for "a meter photograph was removed".

`test_nothing_outside_the_allowlist_is_logged` asserts the half an allowlist usually leaves
untested: `user_id` is fillable and written on every row, and it must stay out of
`attribute_changes` because who recorded a reading is already the causer.

**What this feature does not do yet**, each a decision rather than an omission:

- **Nothing reaches the cash book.** A reading does not create a `Transaction`, automatically or
  otherwise. Wiring it up raises questions this does not answer — what happens to the
  transaction when the reading is edited or deleted, and whether a reading means money owed or
  money received. Until those are settled, two independent records are honest and one linked
  pair would be misleading.
- **No history of the price itself.** "What was the rate in July" is answerable by opening a July
  reading, and not otherwise — there is no screen listing when the price changed. That is the
  trade the simplification made, and the rate on each row is what keeps it a lookup rather than a
  loss.
- **One meter.** A second one would need something to file readings under, which is the `rooms`
  table that was just removed. Bringing it back is a column plus a select, and the rate on the
  reading means nothing already recorded would change.
- **No standing charge and no minimum usage.** `total_amount` is `usage × rate` and nothing
  else. Both are common in kost billing and both would be columns here, so they are cheap to add
  — but adding them unasked would have put figures on a bill nobody specified.
- **No bill to hand anyone.** The panel shows the total; there is no per-period PDF or
  spreadsheet. `pdf.buku-kas` and `TransactionsExport` are the shapes to copy, and PDF and
  Spreadsheet record what silently goes wrong in each.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.

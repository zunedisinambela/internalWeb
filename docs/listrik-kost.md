# Listrik kost

One screen. A period of electricity use is one row: the figure the meter opened at, the figure
it closed at, a photograph behind each, and the amount paid for it.

| Piece | Where |
|-------|-------|
| Reading | `App\Models\MeterReading`, `/meter-readings` (`app/Filament/Resources/MeterReadings/`) |
| Photos | media collections `MeterReading::PHOTOS_START` / `::PHOTOS_END`, private `local` disk |
| Amount | `App\Filament\Forms\Components\RupiahInput` on `total_amount`, the project's grouped rupiah field |

**This panel records a bill; it does not compute one.** That is the shape of the whole feature
and the shortest way to predict what belongs in it. The two meter figures are evidence of how
much was used, `total_amount` is what the bill said, and nothing multiplies the one by the
other.

**It used to be three screens, and it lost the arithmetic after it lost the screens.** Two
migrations, in that order, and both destructive:

| Migration | Removed | What that destroyed |
|---|---|---|
| `2026_08_27_120000_simplify_kost_to_one_screen` | `rooms`, `electricity_tariffs`, `meter_readings.room_id` | room names and occupants, and the history of *when* the price changed |
| `2026_08_27_140000_bill_the_amount_paid_rather_than_a_rate` | `meter_readings.rate`, replaced by `total_amount` | the decomposition of each bill into kWh and price |

Neither one changed what any recorded period costs, and the reasons differ:

- **`meter_readings.rate` was always a copy, never a join**, which is why the tariff table could
  be dropped without touching a single recorded amount. Every reading already carried the price
  it was billed at.
- **`total_amount` was backfilled with exactly what the accessor it replaced returned**,
  `(end_kwh - start_kwh) * rate`. Every existing period kept the figure it already showed; what
  went is the ability to say how much of that figure was consumption and how much was price.

The first migration was written for a tenant instead of a landlord. The second went further and
noticed the panel was still *computing* a bill, which is something the person paying one never
does — they are handed a figure. So the figure is what the row records.

**The amount lives on the reading, and nothing derives it.** This is the load-bearing decision,
and it is the third form of one guarantee that has survived every rewrite of this feature: a
recorded period cannot be repriced. It was first held by copying the tariff onto each row rather
than joining it, then by there being no shared rate anywhere, and now by there being no
calculation at all. `test_a_later_reading_does_not_change_an_earlier_bill` is the assertion that
keeps it, and `test_editing_a_reading_leaves_the_recorded_bill_alone` is the more dangerous half
— a default or a mutator writing over the stored figure during an ordinary save.

**The amount is asked for on every reading and is deliberately not prefilled.** The rate it
replaced *was* prefilled, and rightly: a price repeats month to month, so carrying it forward
was one fewer thing to type and a change was a field already on screen waiting to be corrected.
An amount does not repeat. A prefill would put last month's figure into the one field on the
form that nothing else can contradict — a wrong bill that looks exactly like a right one.
`test_the_bill_is_not_prefilled_from_the_previous_reading` is what stops that convenience being
added back.

**The ceiling is what replaced the arithmetic as a guard.** A rate had a plausible range
(`WholeRupiah(min: 1, max: 100_000)`) and a bill computed from it inherited one. A typed amount
inherits nothing, so one extra zero is a figure the form accepts, the column stores and no other
figure on the row disagrees with — the reason the field carries
`->rule(new WholeRupiah(min: 0, max: 50_000_000))` on top of `RupiahInput`'s own. Filament's
`->rule()` appends, so both apply; narrowing composes and there is no way to widen by accident.

**Rp 0 is a real answer, not an empty field.** A period where the meter did not move genuinely
costs nothing, so the field is `->allowingZero()` and `->required()` is what catches a blank one
— a zero has to be typed. Refusing it with *"Jumlah harus rupiah penuh"* would describe the
wrong problem.

**kWh are whole integers, both figures.** Same reasoning as the rupiah columns under Keuangan:
SQLite has no real `DECIMAL`, and a fractional meter figure would be evidence nobody can check
against a photograph. Meters here read whole kWh, so fractions are refused outright rather than
rounded silently.

**`usage_kwh` is derived; `total_amount` is stored.** That split is the whole change, and the
direction is worth stating because it reverses a rule the rest of the row follows. Consumption
is an accessor, so it cannot disagree with the two columns it comes from. A stored total was
refused for the same reason for as long as it was computable from three columns beside it — a
fourth number able to contradict them is worse than a query. It is now the number typed off the
bill, so there is nothing left for it to contradict.

The cost lands on sorting, and it landed on the other side than before: `usage_kwh` has no column
to order by and spells its sort out (`->sortable(query: …)` with an `orderByRaw`), while
`total_amount` is now an ordinary `->sortable()`.

`usage_kwh` is not clamped at zero. The form refuses a closing figure below the opening one, so a
negative can only come from a row written outside it — and showing it in red is how that becomes
visible. `max(0, …)` would render the same broken row as a plausible period of no consumption.
`total_amount` needs no such note: the column is unsigned.

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

**Two things are prefilled from the previous reading**, which is what makes them one continuous
meter rather than a pile of unrelated periods: `start_kwh` from the previous `end_kwh` and
`start_read_at` from the previous `end_read_at`. `MeterReading::previous()` is the single query
behind both. The rate was the third and went with the field; the amount that replaced it is not
prefilled, for the reason given above.

Both read the latest *period*, not the last row written — `previous()` orders by `end_read_at` —
so a correction entered out of order still leaves the newest closing figure as the one the next
reading opens at. `test_the_prefill_comes_from_the_latest_period_not_the_last_row_written` pins
that.

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

**The view screen prints one figure under Tagihan, not two.** A rate sat beside the total for as
long as the total was `usage × rate`, so that both halves of the multiplication were on screen
and the result was checkable against them. There is no multiplication to check any more, and the
kWh the amount was charged for are already at the top of the same screen.

**The form shows consumption beside the amount for what is left of that.** `usage_preview` is a
read-only `TextEntry` reading the two kWh fields live, sitting in the same section as the amount
being typed. It is not a factor in anything — it is the only cross-check the screen still has,
and it works precisely because neither figure is derived from the other: a bill that jumped while
the meter barely moved is visible in one glance, and the two can genuinely disagree.

**Auditing is split two ways**, because no single mechanism can see all of it:

| Change | Recorded by |
|--------|-------------|
| `start_kwh`, `start_read_at`, `end_kwh`, `end_read_at`, `total_amount`, `note` | `LogsActivity`, log name `meter_reading` |
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
- **No price per kWh, anywhere.** "What was the rate in July" is no longer a question this table
  answers. It is *derivable* for a period — `total_amount / usage_kwh` — but only as a number
  that happens to divide, and a bill handed over by a landlord rarely does. Nothing in the panel
  computes or displays it, deliberately: putting it back on screen would invite the
  multiplication the feature was rewritten to stop performing.
- **One meter.** A second one would need something to file readings under, which is the `rooms`
  table that was removed. Bringing it back is a column plus a select, and the amount on the
  reading means nothing already recorded would change.
- **No standing charge and no minimum usage, and now nowhere to put one.** They used to be
  cheap additions because a bill was assembled here out of parts. It no longer is — the amount
  arrives already containing whatever the landlord charged. Recording the parts again would mean
  recording a breakdown the tenant is not given.
- **No bill to hand anyone.** The panel shows the total; there is no per-period PDF or
  spreadsheet. `pdf.buku-kas` and `TransactionsExport` are the shapes to copy, and PDF and
  Spreadsheet record what silently goes wrong in each.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.

# Oriflame

Direct selling, recorded from the consultant's side. One order is one row carrying three
figures — what the consultant paid, what the postage cost, what the customer was charged — and
the whole feature exists to keep the difference between them readable per sale and per
customer. A separate column, `quantity`, counts the items without touching any of the three; it
is what a bonus of one free item per twenty bought is answerable from. Two screens under one
`Oriflame` navigation group.

The worked example it is written for: Zunedi's order costs Rp 190.000 from Oriflame, Rp 10.000
to send, and is sold on at the catalogue price of Rp 220.000. The margin is Rp 20.000.

| Piece | Where |
|-------|-------|
| Sale | `App\Models\Sale`, `/sales` (`app/Filament/Resources/Sales/`) |
| Customer | `App\Models\Customer`, `/customers` |
| Amounts | `App\Filament\Forms\Components\RupiahInput` on `App\Rules\WholeRupiah` |
| Figures | `sales.marketing_price`, `sales.shipping_cost`, `sales.catalog_price` |
| Count | `sales.quantity`, and `Sale::FREE_ITEM_THRESHOLD` / `Sale::$free_quantity` on top of it |
| Bonus per customer | `Customer::$total_quantity` / `$free_quantity` / `$quantity_to_next_free_item`, over `Customer::freeItemsFor()` |
| Handover | `App\Models\FreeItemRedemption`, `free_item_redemptions`, on the customer screen via `Resources/Customers/RelationManagers/FreeItemRedemptionsRelationManager` |
| Owed | `Customer::$free_quantity_claimed` / `$free_quantity_available` |
| Resi | `free_item_redemptions.tracking_number` plus media collection `FreeItemRedemption::SHIPPING_PROOFS`, private `local` disk |
| Evidence | media collections `Sale::PAYMENT_PROOFS` / `::SHIPPING_PROOFS`, private `local` disk |

**Ongkir is the consultant's cost, not a line on the customer's bill.** The customer pays
`catalog_price` and nothing more, so the margin is `catalog − marketing − shipping`. Billing it
on top would be a fourth figure — what was actually charged — and after the fact the two
readings are indistinguishable from the same three columns, which is why the model states one
and a test asserts it.
`test_shipping_is_a_cost_to_the_consultant_not_a_charge_to_the_customer` records two orders
identical but for the postage and asserts the customer paid the same for both while the
consultant earned different amounts. Without it the feature passes every other test under either
reading.

**`quantity` counts items and does not reprice anything.** The three figures stay totals for the
whole order — what the consultant paid, what the postage cost, what the customer was charged —
so `total_cost` and `profit` read exactly as they did before the column existed. The other
reading was available and was rejected: making them per-unit prices would silently reinterpret
every row already recorded, since a stored `190.000` would start meaning `190.000 × quantity`
with nothing on the row saying which reading was intended. That is the same class of change the
rate-on-the-reading rule under Listrik kost exists to prevent, arriving through a new column
instead of through a join. `test_the_quantity_does_not_change_the_money` records two orders with identical
figures and item counts of 1 and 20 and asserts they cost and earn the same; without it the
feature passes every other test under either reading.

The column defaults to **1**, in the migration as well as on the form. The migration default is
what the rows already in the table say — an order recorded before anyone was counting was an
order of at least one item, and `0` would make them read as orders of nothing while feeding the
bonus a figure nobody entered. It is also what makes the column addable at all: a `NOT NULL`
column cannot be added to a table that already holds rows without one.
`test_a_sale_recorded_without_a_quantity_counts_as_one` pins it.

**The free item is a count, not money.** `Sale::$free_quantity` is
`intdiv(quantity, FREE_ITEM_THRESHOLD)` — one free item per 20 bought — derived for the same
reason the margin is, so correcting a quantity moves the bonus with it and the two cannot
disagree. It is deliberately absent from `total_cost` and `profit`: whether the free item is
still paid for to Oriflame has not been decided, and folding a guess in would put a figure on
the margin that nobody entered. `test_the_free_item_carries_no_money` asserts the margin is untouched at exactly the threshold.

**No sale screen shows a bonus, and none should.** The form, the list and the view screen each
used to carry the per-sale figure beside the money; all three were removed once the customer
became the span the bonus is counted over. A per-order figure on a sale screen is a second,
always-smaller answer to the same question — an order of ten reads "0 gratis" while the customer
it belongs to has just earned one — shown on the screens least able to explain the difference.
`Sale::$free_quantity` stays as an accessor because it is what
`test_the_free_item_is_counted_across_orders_not_per_order` asserts *against* to keep the two
readings from being collapsed into one; it has no UI left. Two consequences worth knowing before
putting one back: the quantity field on `SaleForm` is no longer `->live()`, because that was
there only to move the removed preview, and a bonus preview cannot honestly be rebuilt on that
form at all — the figure depends on the customer's *other* orders, which an unsaved form would
have to go and query.

**The bonus is counted twice, over two different spans, and that is the point.** `Sale::$free_quantity`
divides one order's own count; `Customer::$free_quantity` divides everything that customer has
ever bought — `Customer::$total_quantity`. Two orders of ten items earn **nothing** on either
sale and **one free item** on the customer, and neither figure is wrong: the sale answers "does
this order qualify on its own", the customer answers "what is this person owed".

The customer reading is not a sum of the sale readings, and must not become one. Summing them
discards every remainder at the row boundary, so the same twenty items would be worth a free one
or nothing at all depending only on how many trips they were bought in — and buying ten a month
is the ordinary case, not the exception. `test_the_free_item_is_counted_across_orders_not_per_order`
asserts both halves in one test for that reason: without the per-sale assertion it would still
pass if the customer accessor were quietly rewritten as `sales->sum(fn ($s) => $s->free_quantity)`.

The division itself lives in `Customer::freeItemsFor()` because two screens reach it by different
routes. The view screen walks the loaded relation like every other total here; the **list** cannot
— a walk per row is a query per row — so `CustomersTable` reads the same figure back from a
`->sum('sales', 'quantity')` subquery, which arrives as `null` for a customer with no sales at
all. One static, so the two cannot start disagreeing about what twenty items are worth.

**What was earned is derived; what was collected is recorded.** Those are two different kinds of
fact and the feature keeps them apart. `Customer::$free_quantity` divides the orders and moves
whenever a `quantity` is corrected — it cannot disagree with the sales because it is made of
them. Whether the customer actually turned up for their free item is not answerable from any
order, so it is a row: `App\Models\FreeItemRedemption`, one per handover, carrying the date, how
many were taken, the courier's resi and a photograph of it.

`Customer::$free_quantity_claimed` sums those rows and `$free_quantity_available` is
`earned − claimed`. A counter column on `customers` was the alternative and is the usual mistake:
it would be a number able to disagree with the handovers it was incremented from, and deleting a
handover recorded by mistake would leave it behind.

**`$free_quantity_available` is deliberately not clamped at zero**, for the reason `Sale::$profit`
and `MeterReading::$usage_kwh` are not. The form refuses to hand over more than is owed, so a
negative cannot come from the panel — it can only mean a handover was recorded against an order
later corrected downwards. That is a real bookkeeping problem, and red is how it gets noticed;
`max(0, …)` renders the same customer as one who happens to be owed nothing.
`test_a_bonus_collected_and_then_unearned_reads_as_a_negative` pins it, and it is also the answer
to "why doesn't a handover reduce the earned figure": a handover that happened is not undone by an
edit to an order, and the two figures disagreeing *is* the report.

**The handover screen is a relation manager, not a resource**, and that decision carries three
consequences worth knowing:

- **It is writable only because `isReadOnly()` is overridden.** Filament makes relation managers
  read-only on a `ViewRecord` page by default, and the failure is silent in the worst way — the
  table renders with its rows and the create, edit and delete buttons are simply absent, exactly
  as though the user lacked a permission. The override is on the manager rather than on the panel
  flag, which would change every manager added later.
- **Shield generates permissions from resources, so this model has none.** `FreeItemRedemptionPolicy`
  is written by hand against the *customer's* permissions — reading is `View:Customer`, every
  write is `Update:Customer` — because a policy naming `ViewAny:FreeItemRedemption` would refuse
  everybody, including a super admin: the permission does not exist to be granted. If handovers
  ever get a screen of their own, that stops being right; the policy says so in place.
- **There is no global list.** "Every free item collected in August, across all customers" is not
  answerable from the panel, only from `activity_log` under the `free_item_redemption` log name.
  That was the trade for keeping the figures beside the bonus they draw down.

**The form refuses to hand over more than is owed**, and the check reads `fresh()` rather than the
owner record the page was rendered with. That is the whole substance of
`FreeItemRedemptionsRelationManager::availableFor()`: a handover saved a moment ago is not in the
relation loaded at render time, so a check against the copy in memory would let the same bonus be
collected twice within one page.
`test_a_second_handover_is_measured_against_what_the_first_one_left` is what holds it. When an
existing row is edited its own quantity is added back, or a row that used up the last bonus could
never have its resi corrected — `test_an_existing_handover_can_be_edited_without_being_refused_by_itself`.
The create button is *hidden* rather than disabled once nothing is owed: a form every submission of
which is refused explains the rule worse than its absence beside the empty state does.

**The resi is a column and a photograph, not one or the other.** `tracking_number` is searchable
and can be pasted into the courier's site; the photograph is what survives when the number was
never written down. Both are optional, because a free item handed over in person has no resi at
all — the common case for a nearby customer. The collection is named `shipping-proofs`, the same
name `Sale` uses: media rows are keyed by morph, so the two never meet, and naming it to match
says it is evidence of the same kind of event. Private disk and `->visibility('private')` on
every component rendering it, for the reason under Media — a resi carries the customer's home
address.

`FREE_ITEM_THRESHOLD` is a constant rather than a settings row, because today it is one rule for
everybody and a table nobody edits drifts out of date in silence. When it starts varying per
customer or per month it becomes a column and the constant becomes its default — the accessor is
the only thing that reads it, so moving it costs one line.

**Every total is derived, none is stored.** `Sale::$total_cost` and `Sale::$profit`;
`Customer::$total_spent` and `$total_profit`. A stored total would be a further number able to
contradict the columns it came from, and nothing would say which was right. Two consequences:

- **The margin's sort has to be spelled out.** The three figures are real columns and sort
  themselves, but the margin is not, and `->sortable()` alone on a `->state()` column renders a
  control that silently reorders by nothing. `Sale::PROFIT_EXPRESSION` holds the SQL, beside the
  accessor it has to agree with.
- **The customer list shows counts, not a margin.** The money totals walk a loaded relation, so a
  margin per row would be a query per customer; a `withSum` of the margin would be a second copy
  of arithmetic `Customer::$total_profit` already owns. The view screen calls
  `loadMissing('sales')` instead. The **item** count is the one figure the list does aggregate —
  `->sum('sales', 'quantity')` is a single subquery and the arithmetic on top of it is one
  `intdiv` shared with the accessor, so there is no second copy to drift. The handovers are summed
  the same way, through `->modifyQueryUsing(… withSum('freeItemRedemptions', 'quantity'))`, because
  what the list is scanned for is who is still **owed** something rather than who has earned
  something. Either aggregate arrives as `null` for a customer with no rows on that side, which is
  why `Customer::freeItemsFor()` takes a nullable int.

**`RupiahInput` is where the grouped-rupiah trio lives.** `->live(onBlur)` +
`afterStateUpdated`, `->formatStateUsing()` and `->dehydrateStateUsing()` have to travel
together: drop the last one and the column receives the string `"1.500.000"`, which SQLite's
loose typing casts and stores as **1** — no exception, no validation message, and a price that
reads as a rounding bug months later. Three fields on this form need it.
`Transaction::$amount` and `MeterReading::$rate` predate it and still spell the trio out inline;
converting them is a separate change to tested financial code.

**`->allowingZero()` is on the ongkir field and nowhere else.** `WholeRupiah`'s floor is 1,
which is right for a price — an amount of nothing is a half-filled form and `->required()` is
what should say so — and wrong for a cost that is genuinely often nothing, since most orders are
handed over rather than posted. Refusing the commonest case with *"Jumlah minimal Rp 1"*
describes the wrong problem.

The mechanism is worth knowing before copying it: `RupiahInput::setUp()` registers its
`WholeRupiah` at `make()` time, before any chained call runs, and Filament's `->rule()` appends
rather than replaces — so a later call cannot take that rule back. The rule is therefore
registered as a **closure**, which `CanBeValidated::getValidationRules()` evaluates at
validation time and which reads the `$minimumRupiah` property the chain has since changed.
`test_a_zero_shipping_cost_is_accepted` and `test_a_zero_price_is_still_refused` are a pair: the
second is what keeps the lifted floor from spreading to the prices.

**Laravel's `->lte()` cannot compare two grouped rupiah fields, and fails quietly.** It picks
its comparison from `is_numeric()`, which answers **true** for `"150.000"` — a valid float
string meaning 150.0 — and **false** for `"1.500.000"`, which has two dots. So one side of the
same comparison is read as a number and the other as a *string length*, with no error either
way. It happens to be right whenever both figures land in the same shape, which is most of the
time while testing. `RupiahInput::notGreaterThan()` compares through `WholeRupiah::toInteger()`
instead, which is the only reading that always matches what the column will receive.
`test_a_marketing_price_above_the_catalogue_price_is_refused` picks its figures so the broken
reading and the correct one disagree.

A marketing price *above* the catalogue price is refused — in practice it is the two figures
entered the wrong way round. Equal prices are accepted: selling on at cost earns nothing and is
still a real sale. Below that the margin is **not** clamped, for the reason
`MeterReading::$usage_kwh` is not: an order posted a long way can genuinely lose money, and
rendering that in red is how it becomes visible. `max(0, …)` would render the same order as a
plausible sale earning nothing.

**An order carries two kinds of evidence, in a collection each.** `payment-proofs` is the
customer's transfer receipt — proof the money arrived. `shipping-proofs` is the courier's resi —
proof the goods left. Both optional, both accepting several files: a split payment is two
transfers, and an order sent in two parcels is two resi.

**Two collections, not one holding both**, and it is the same decision `MeterReading` makes
about its two ends. Which file answers which question is the point of attaching them at all, and
a single collection could only express that by upload order — which reordering or deleting one
file destroys silently, with nothing saying the pairing has shifted. `collection_name` on the
row is what nothing in the UI can scramble.
`test_an_attachment_belongs_to_the_field_it_was_uploaded_against` pins it. The form lays the two
drop zones out side by side so uploading a resi against the payment field takes a deliberate
mistake rather than a careless one.

**They are on the private disk**, and there is nothing optional about it here: a transfer
receipt carries a bank account number and a name, a resi carries the customer's home address —
the same address `customers.address` now holds as text, except that a photograph cannot be
redacted, corrected or searched, only shown or withheld.
`registerMediaCollections()` pins `->useDisk('local')`, and all three Filament components that
render one set `->visibility('private')` — drop it from any one of them and that surface
silently renders broken images with nothing in the log. Each of the three is built from a
private factory method on its schema or table class for exactly that reason; a second copy is a
second chance to lose the flag. See Media for how the private disk is served and what that
protection does not cover.

The `thumb` conversion is `->nonQueued()`, doing the same double duty it does on `Transaction`:
it survives a deploy with no queue worker, and being re-encoded it drops almost all of the EXIF.
That second reason is weaker here than on a meter photograph — a transfer receipt is usually a
screenshot — but a resi photographed at the counter is not. See Gotchas.

**Both are opened full size by clicking**, the same lightbox the cash book's receipts use — two
calls on the entry, and `SaleInfolist::attachments()` carries them for both collections. The two
wrappers take **different** `data-lightbox` keys, `payment-proofs` and `shipping-proofs`: the
script pages through every `<a>` inside one marked element, so a shared key would page from a
transfer receipt straight into a courier resi — the pairing two collections exist to keep apart.
The `href` is a signed link to the **original**, not to the thumbnail, which is what makes a
resi's tracking number readable and is also the EXIF-bearing copy. See Media, and see Gotchas
for what a resi photographed at the counter carries.
`test_each_attachment_is_its_own_link_on_the_view_screen` attaches **two** files to one
collection deliberately: with one, a `->url()` closure that lost its `state` parameter renders
identical HTML and the test passes anyway.

**The address is a `text` column, and the form follows from that.** A full Indonesian address —
jalan, RT/RW, kelurahan, kecamatan, kota, kode pos, plus the patokan people actually navigate by
— runs past the 255 characters a `string` would give it, and the overflow is silent on a database
that is not in strict mode. SQLite enforces no length at all, so the local suite could never
catch it: `test_a_long_address_survives_the_round_trip` asserts the round trip rather than the
column type, which really guards against a later migration narrowing it. The field is a
`Textarea` with no `maxLength` for the same reason — a cap there would refuse what the row can
hold — and the infolist renders it `white-space: pre-line`, because the line breaks somebody
typed *are* the address's structure. That is `->extraAttributes(['style' => …])`, a CSS
declaration on the wrapper — not `HtmlString` and not a raw echo, so the value stays escaped.
Reaching for markup to get those line breaks is the trap the escaping table under Gotchas
describes, and this is a field where the text is somebody's home address.

**Its column is toggled off by default and still searchable.** Not a contradiction:
`CanSearchRecords::applyGlobalSearchToTableQuery()` skips a column for `isHidden()` — the
`->hidden()` / `->visible()` API — and never consults `isToggledHidden()`, so the column manager
takes it off the list without taking it out of the search. It is the one column here that would
need a row to itself, and looking a customer up by street is a real question, so both are had at
once. It rests on a vendor internal rather than a documented promise, which is why
`test_an_address_is_searchable_while_its_column_is_hidden` pins it — and why that test asserts
the content is absent from the rendered list rather than calling `assertTableColumnHidden()`,
which asserts `isHidden()` and would be testing the opposite thing.

**Customers are retired, not deleted.** `sales.customer_id` is `restrictOnDelete`, so
`is_active` is the exit. The rule is enforced twice on purpose:
`canDelete()` on the resource turns the refusal into a missing button rather than a
`QueryException`, and the foreign key covers tinker and anything else that never asks the
resource. An inactive customer stays *selectable* on the form while marked `(tidak aktif)` — a
filter would leave the edit screen for an old sale with an empty select and no explanation.

**What was removed, and what went with it.** This feature was built as `Sale` → `SaleItem` →
`Product`: a catalogue of products each carrying two prices, sale lines with a quantity, and a
**price snapshot per line** so that entering a new monthly catalogue could not rewrite a sale
already recorded. `Resources/Sales/Actions/RefreshPricesAction` was the escape hatch out of that
snapshot. All of it is gone — `products`, `sale_items`, `ProductResource`, `ProductPolicy`, the
twelve `*:Product` Shield permissions and the `sale_item` / `product` log names.

The narrowing is deliberate: what gets written down for one order is three figures, and the
lines were machinery for a question nobody was asking. Three things follow.

- **The snapshot problem does not come back.** It existed because a line read prices that lived
  on another row. There is no catalogue table left to join to, so every figure on a sale was
  typed onto that sale and nothing outside it can move one. That is also why there is no
  refresh-prices button: correcting a figure is editing the field.
  Listrik kost reached the same conclusion from the other direction: its `RefreshRateAction`
  existed only because the rate came from a table the reading joined nothing to, and it was
  deleted along with that table. No action of that shape is left in the project — which means
  the next copied figure has no worked example, and Listrik kost is where the properties one
  needs are written down.
- **Per-product history is genuinely lost.** "What sells best" and "what did this product cost
  in July" are no longer answerable, and `activity_log` does not cover it either — there is
  nothing left recording which products an order contained. Getting it back means bringing the
  lines back, not adding a column to `sales`.
- **The two dropped tables were removed by deleting their migrations, not by adding a migration
  that drops them.** The feature was eight days old, the local database held no rows, and both
  files were still the original `create_*`. That keeps the history readable and `migrate:fresh
  --seed` — the documented rebuild — correct. It also means **an environment that already ran
  those migrations will not be cleaned by pulling this change**: the edited
  `create_sales_table` will not re-run there, so `products` and `sale_items` simply stay behind
  with three columns missing from `sales`. Anywhere but a throwaway database, rebuild with
  `migrate:fresh` rather than `migrate`. That is the same class of one-off as the WIB timestamp
  shift under Locale and timezone: a fix applied by hand once, deliberately not left as a
  migration that would re-run.

**Auditing is two log names, one per thing a reader would filter for:**

| Change | Recorded by |
|--------|-------------|
| `customer_id`, `occurred_at`, `quantity`, all three figures, `note` on a sale | `LogsActivity`, log name `sale` |
| an attachment removed, from either collection | `AppServiceProvider::registerMediaDeletionLogging()`, event `sale_attachment_deleted` |
| a customer's name, phone, address or status | `LogsActivity`, log name `customer` |
| a free item collected: date, count, resi, note | `LogsActivity`, log name `free_item_redemption` |
| the resi photograph of a handover removed | `AppServiceProvider::registerMediaDeletionLogging()`, event `redemption_resi_deleted` |
| an attachment added or replaced | **nothing** |

All three figures are on the `sale` allowlist deliberately: they are the whole record of the
order, so a margin that reads differently today than it did last month is only explicable from
the log. `quantity` is on it for the parallel reason — it is what the free item is owed on, so a
count changed after the fact has to stay explicable the same way a repriced order does. The
bonus itself is *not* logged and must not be added: it is derived from `quantity` by
construction, so an entry for it would be a second record of the same change, able to drift from
the column it came from. `phone` is on the `customer` allowlist for a different reason — a number changed on the
wrong row is how a message about an order reaches the wrong person — and `address` for the same
reason at a higher cost: a parcel sent to a stale address is lost rather than merely misdirected,
so the previous value has to stay recoverable. What that costs is in Access control: the log then
holds home addresses, under a permission of its own and a retention that is blank by default.

Attachments are a relation, so `LogsActivity` cannot see them — the same split `LogRoleChange`
makes for roles. Both collections write the **same** event key: which one lost the file is
already in the entry's `collection` property, and a second key would mean remembering two of
them to filter for "a sale attachment was removed". Deleting a whole sale writes its own
`deleted` entry *and* one `sale_attachment_deleted` per file, which
`test_deleting_a_sale_audits_the_row_and_each_attachment` asserts by count — it depends on two
unrelated mechanisms lining up, medialibrary removing files from the `deleting` event and the
`Media::deleted` listener firing once per row.

**What this feature does not do yet**, each a decision rather than an omission:

- **Nothing reaches the cash book.** A sale does not create a `Transaction`. Same unanswered
  questions as the meter readings: what happens to the transaction when the sale is edited or
  deleted, and whether a sale is money received now or money owed. Two independent records are
  honest until those are settled.
- **The free item does not reach the money.** The count side is now complete — earned, collected
  and owed are all answerable, and a handover carries its date and resi — but none of it touches
  a figure: the bonus is not subtracted from `marketing_price` and not added to what the customer
  received. The unanswered question is whether the
  consultant still pays Oriflame for it — if they do it is already inside `marketing_price` and
  the margin is right as it stands; if they do not, the figure is a discount and needs saying so
  somewhere. Deciding it is a change to the accessors on `Sale`, not a new column.
- **No discount to the customer.** The margin assumes the customer pays `catalog_price` exactly.
  Giving a friend a break would need a fourth figure — what was actually charged — and the
  margin would then be `charged − marketing − shipping`. It is a column and a form field, cheap
  to add; it was left out because the example this was built from had no such case.
- **No payment status.** Nothing records whether the customer has paid. `note` is where "bayar
  minggu depan" goes today. A real answer is a column plus a filter plus a total of outstanding
  money, which is a small feature of its own.
- **No monthly recap, no export.** The list filters by customer and date range, and the figures
  are on screen; there is no per-month margin report and no spreadsheet.
  `TransactionsExport` and `App\Reports\CashBook` are the shapes to copy, and Spreadsheet below
  records what silently goes wrong.
- **No products and no stock.** See the removal note above — this is now a ledger of orders, not
  a catalogue.

---

Part of the internalWeb documentation. `CLAUDE.md` in the project root carries the
always-loaded rules and the map to every other section; a reference here to a section
name — "see Keuangan", "under Media" — means the file of that name in this directory.

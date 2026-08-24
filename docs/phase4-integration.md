# Phase 4 — Modificadores: integration notes

What shipped in this phase (new files only, nothing existing touched):

| File | Role |
| --- | --- |
| `wp-content/plugins/haramara-core/src/Catalog/ModifierGroups.php` | `haramara_modgroup` CPT + wp-admin editing UI (rules, options, assignment) |
| `wp-content/plugins/haramara-core/src/Catalog/ModifierResolver.php` | `for_product()` — resolved, ordered, serialized groups (the API contract) |
| `wp-content/plugins/haramara-core/src/Catalog/ModifierApplication.php` | `validate()` / `apply()` / `price_delta()` — sale-time enforcement |
| `wp-content/plugins/haramara-core/src/Rest/CatalogRoutes.php` | `GET /haramara/v1/pos/modifier-groups?product_id=` (staff cap, no-store) |
| `apps/pos/src/lib/modifiers.tsx` | `ModifierSheet` React Native component (unimported until seam 6) |

None of it is live until the seams below are applied. Each seam is an exact
edit to a file another engineer owns; apply them in order — 1 activates the
backend, everything after is client plumbing.

## The contract (read first)

**Serialization** (`ModifierResolver::for_product( $product_id )`), mirrored
by the `ModifierGroup` type in seam 2:

```json
{
  "id": 123,
  "name": "Leche",
  "min": 0,
  "max": 1,
  "required": true,
  "options": [
    { "key": "entera",        "name": "Entera",        "price_delta": 0 },
    { "key": "avena",         "name": "Avena",         "price_delta": 15 },
    { "key": "deslactosada",  "name": "Deslactosada",  "price_delta": 0 }
  ]
}
```

- Order: directly-assigned groups first, then category defaults, deduped by
  group ID; within each tier, `menu_order` then title. Groups with no options
  are dropped.
- `key` is a `sanitize_key( sanitize_title( name ) )` slug, unique within its
  group (`"chico"`, `"chico-2"`). Clients send keys back, never names.
- `max` = 0 means no limit; 1 means single-select. **Skippability:** a
  `required` group must be selected with at least `max(min, 1)` options; an
  optional group may be omitted entirely, but once engaged its `min`/`max`
  apply. `ModifierApplication::validate()` and `ModifierSheet` both enforce
  exactly this.
- `price_delta` is MXN **per unit** (may be 0 or negative). Multiply by the
  line quantity when adjusting a line.
- Selections wire shape, per sale line: `[ { "group_id": 123, "option_keys": ["avena"] } ]`.
- `ModifierApplication::apply( $item, $validated )` writes one **visible**
  order-item meta row per group (`Leche: Avena (+$15.00)`; the price hint only
  when an option's delta ≠ 0) plus two hidden rows:
  `_haramara_modifiers` (structured `[{group_id, option_keys}]`) and
  `_haramara_modifiers_delta` (per-unit delta applied). It does NOT save the
  item and does NOT touch totals — the caller adjusts subtotal/total (seam 5).

## 1. `src/Plugin.php` — register the two services

In `service_classes()`, after the WooCommerce foundation block:

```php
			// WooCommerce foundation.
			Woo\Support::class,
			Woo\Inventory::class,

			// Catálogo: grupos de modificadores (Phase 4).
			Catalog\ModifierGroups::class,
```

and in the `// REST + CLI.` block, directly after `Rest\PosRoutes::class`:

```php
			Rest\CatalogRoutes::class,
```

`ModifierResolver` and `ModifierApplication` are static utilities — they are
not Bootable and must NOT be listed. After this seam, `wp-admin → Haramara →
Modificadores` exists and the REST route answers.

## 2. `packages/api-client/src/types.ts` — the shared types

Add (a good spot: right after `PosProduct`):

```ts
/* -------------------------------------------------------------------------- */
/* Modificadores (Catalog\ModifierResolver / Rest\CatalogRoutes)              */
/* -------------------------------------------------------------------------- */

/** One selectable option inside a modifier group. `price_delta` in MXN, per unit. */
export interface ModifierOption {
	key: string;
	name: string;
	price_delta: number;
}

/**
 * One resolved modifier group (Catalog\ModifierResolver::serialize).
 * `max` 0 = no limit, 1 = single-select. A `required` group needs at least
 * max(min, 1) picks; an optional group may be skipped entirely, but once one
 * option is chosen its min/max apply.
 */
export interface ModifierGroup {
	id: number;
	name: string;
	min: number;
	max: number;
	required: boolean;
	options: ModifierOption[];
}

/** GET /pos/modifier-groups?product_id= (Rest\CatalogRoutes). */
export interface ModifierGroupsResponse {
	product_id: number;
	groups: ModifierGroup[];
}

/** Chosen option keys for one group, sent per sale line. */
export interface ModifierSelection {
	group_id: number;
	option_keys: string[];
}
```

Then two edits to existing interfaces:

```ts
export interface PosProduct {
	// … existing fields unchanged …
	/** Resolved modifier groups (absent until the PosRoutes seam lands / on pre-Phase-4 servers). */
	modifier_groups?: ModifierGroup[];
}
```

```ts
export interface WalkInInput {
	items: Array<{ product_id: number; quantity: number; modifiers?: ModifierSelection[] }>;
	// … rest unchanged …
}
```

`apps/pos/src/lib/modifiers.tsx` currently declares structurally identical
local `ModifierOption` / `ModifierGroup` / `Selection` types so it compiles
standalone. Once this seam lands, optionally replace those three local
declarations with re-exports from `@haramara/api-client` (`Selection` ≡
`ModifierSelection`) — the prop shapes are already assignment-compatible
either way.

## 3. `packages/api-client/src/pos.ts` — the client method

Add `ModifierGroup` and `ModifierGroupsResponse` to the `import type` list,
then add next to `products()`:

```ts
	/** Resolved modifier groups for one product (direct first, then category defaults). */
	async modifierGroups(productId: number): Promise<ModifierGroup[]> {
		const res = await this.req<ModifierGroupsResponse>(
			`${NS}/pos/modifier-groups?product_id=${productId}`,
		);
		return res.groups;
	}
```

## 4. `src/Rest/PosRoutes.php` — groups inline in the product feed

So the POS can open the sheet without a per-tap round trip. Add the import:

```php
use Haramara\Core\Catalog\ModifierResolver;
```

and one line in `serialize_product()`:

```php
			'categories'      => array_map( 'strval', (array) wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ) ),
			'modifier_groups' => ModifierResolver::for_product( $product->get_id() ),
```

(Re-align the `=>` column of the array per phpcs.) Cost: `ModifierResolver`
caches the group list per request, so the 100-product feed adds one
`get_posts` total, not one per product. The feed's `private, max-age=30`
header means a group edit reaches tablets within 30 s — same staleness budget
as prices, acceptable. The `GET /pos/modifier-groups` route stays as the
no-store, always-fresh fallback.

## 5. `src/Ordering/WalkInOrders.php` + `PosRoutes::create_walk_in` — apply at sale time

**5a. `PosRoutes::create_walk_in()`** — pass modifiers through. In the
`foreach ( $raw as $line )` loop:

```php
					$items[] = array(
						'product_id' => absint( $line['product_id'] ?? 0 ),
						'quantity'   => absint( $line['quantity'] ?? 0 ),
						'modifiers'  => array_values( (array) ( $line['modifiers'] ?? array() ) ),
					);
```

No sanitizing needed here — `ModifierApplication::validate()` absints every
`group_id` and `sanitize_key()`s every option key itself.

**5b. `WalkInOrders::create()`** — validate all lines BEFORE creating the
order (a bad selection must never leave a half-built completed order), apply
after `add_product()`. Add the import:

```php
use Haramara\Core\Catalog\ModifierApplication;
```

Update the `$items` param docblock:

```php
	 * @param array<int,array{product_id:int,quantity:int,modifiers?:array<int,array<mixed>>}> $items Sale lines.
```

After `$lines = self::resolve_lines( $items );` (which returns lines in input
order, so indexes align) and its error check, insert:

```php
		// Validate every line's modifier selections before any order exists.
		// validate() also enforces REQUIRED groups on lines that sent none.
		foreach ( $lines as $i => $line ) {
			$validated = ModifierApplication::validate(
				$line['product']->get_id(),
				array_values( (array) ( $items[ $i ]['modifiers'] ?? array() ) )
			);
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$lines[ $i ]['modifiers'] = $validated;
		}
```

Then replace the add-product loop body:

```php
		foreach ( $lines as $line ) {
			$item_id = $order->add_product( $line['product'], $line['quantity'] );

			if ( array() === $line['modifiers'] ) {
				continue;
			}

			$item = $order->get_item( $item_id );
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			ModifierApplication::apply( $item, $line['modifiers'] );

			// price_delta() is per unit; scale by the line quantity.
			$delta = ModifierApplication::price_delta( $line['modifiers'] ) * $line['quantity'];
			if ( 0.0 !== $delta ) {
				$item->set_subtotal( (string) ( (float) $item->get_subtotal() + $delta ) );
				$item->set_total( (string) ( (float) $item->get_total() + $delta ) );
			}
			$item->save();
		}
```

The existing `$order->calculate_totals()` call downstream sums the stored
line totals, so the adjusted lines flow into the order total, `/pos/summary`
revenue, and the corte with no further changes. The visible meta rows ride
along automatically into admin order screens, receipts/emails, and any item
serialization that includes meta.

**Rollout order matters:** `validate()` rejects a line that omits a REQUIRED
group, and an old POS build never sends modifiers. Ship server + new POS
build first; only then mark groups "Obligatorio" in wp-admin. (Optional
groups are safe to create immediately — omission is valid.)

## 6. POS app — wiring `ModifierSheet` into `mostrador.tsx`

`import { ModifierSheet, type Selection } from '../../lib/modifiers';`

- The ticket today is `Record<product_id, quantity>`. A product with
  modifiers needs per-selection lines: key lines by
  `` `${product.id}:${JSON.stringify(selections)}` `` (or an incrementing
  line id) and store `{ product_id, quantity, selections, priceDelta }` per
  line — two lattes with different milks are different lines.
- On tapping a tile whose `modifier_groups` (seam 4) is non-empty, render
  `ModifierSheet` in a modal:
  `<ModifierSheet groups={product.modifier_groups} onConfirm={(selections, priceDelta) => addLine(product, selections, priceDelta)} onCancel={closeModal} />`.
  Tiles without groups keep the current instant-add path.
- Line display price = `product.price + priceDelta` (both per unit); the
  charge payload becomes
  `items: lines.map((l) => ({ product_id: l.product_id, quantity: l.quantity, modifiers: l.selections.length > 0 ? l.selections : undefined }))`.
- The sheet re-validates nothing after confirm — the server is authoritative;
  surface a `haramara_modifier_*` error from `createWalkIn` via the existing
  `notify()` path and reopen the sheet.

## 7. Web storefront (Woo product page)

The FSE product page uses core Woo blocks, so render and capture groups with
hooks (a natural home: a small new Bootable `Woo\ModifierFrontend` service —
do NOT bolt it onto `Catalog\ModifierGroups`, which is admin/storage only):

- `woocommerce_before_add_to_cart_button`: `ModifierResolver::for_product()`;
  render each group as a fieldset — radios when `max === 1`, checkboxes
  otherwise — named `haramara_mod[<group_id>][]` with the option `key` as
  value, label `name` + `(+$15.00)` hint when delta ≠ 0. Every label is an ES
  string already; EN comes free via the `/en/` dictionary once the labels are
  added to `data/translations.php` (per CLAUDE.md every new user-facing ES
  string needs its pair there).
- `woocommerce_add_to_cart_validation` (filter, 3 args): rebuild
  `[{ group_id, option_keys }]` from `$_POST['haramara_mod']`, run
  `ModifierApplication::validate( $product_id, $selections )`; on `WP_Error`,
  `wc_add_notice( $error->get_error_message(), 'error' )` and return false.
- `woocommerce_add_cart_item_data` (filter): stash the **validated** array as
  `$cart_item_data['haramara_modifiers']`; also fold its serialization into
  the cart-item key input so different selections never merge into one line
  (returning distinct data already does this).
- `woocommerce_before_calculate_totals`: for each cart item carrying
  `haramara_modifiers`, `$item['data']->set_price( (float) $item['data']->get_price() + ModifierApplication::price_delta( $item['haramara_modifiers'] ) )`.
- `woocommerce_checkout_create_order_line_item` (4 args): if the cart item
  carries selections, `ModifierApplication::apply( $item, $cart_item['haramara_modifiers'] )`.
  Do NOT adjust the line subtotal here — the cart price already includes the
  delta on this path; `apply()` only writes meta, so there is no
  double-count.
- Store-API note: the customer app orders through `wc/store` cart endpoints,
  which run the same `add_to_cart_validation` / `add_cart_item_data` /
  `before_calculate_totals` hooks — pass the same `haramara_mod` shape via
  the Store API `extensions` mechanism when the app grows modifier UI
  (`ExtendSchema` registration; out of Phase 4 scope).

## Verifying after seam 1 lands (wp-env, read-only)

```sh
# The route exists (401/403 without auth is correct; 404 means seam 1 missing):
curl -si 'http://localhost:8892/wp-json/haramara/v1/pos/modifier-groups?product_id=1' | head -1
# With staff credentials:
curl -su 'admin:APP PASSWORD' 'http://localhost:8892/wp-json/haramara/v1/pos/modifier-groups?product_id=<id>'
```

Storage reference (all on the `haramara_modgroup` post):
`_haramara_mod_min` (int) · `_haramara_mod_max` (int, 0 = no limit) ·
`_haramara_mod_required` ('1'/'') · `_haramara_mod_options`
(`[{name, price_delta}]`, ordered) · `_haramara_mod_products` (int[]) ·
`_haramara_mod_cats` (product_cat term int[]). Group ordering = `menu_order`
("Orden" box) then title.

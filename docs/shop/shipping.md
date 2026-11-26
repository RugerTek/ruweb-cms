# Shop module: shipping

How a paid order reaches the customer: store pickup (free) or home delivery with a **fixed price per zone**. No
carrier integration, weights or distance rules. This doc owns the delivery options, where they are set and what an
order keeps of them. The purchase flow is in [checkout.md](checkout.md).

## Delivery options

| Option | Stored in | Offered at checkout when |
|---|---|---|
| Store pickup | Settings `shop_pickup_enabled` and `shop_pickup_address` (group `shop`, `database/migrations/2026_11_24_000002_add_checkout_settings.php`) | Enabled **and** with an address. Free. The address is shown at checkout, on the order page and in the emails. |
| Home delivery | `shipping_zones` rows (`App\Models\ShippingZone`): `name`, `price_amount` (integer in the shop currency, `MoneyCast`), `enabled`, `sort_order` | At least one zone is enabled. The customer picks a zone (shown with its price) and gives address and city (required), department and reference (optional). |

At least one option is a requirement to turn selling on (`CheckoutReadiness`, item `delivery`), and while selling
the last option cannot be removed or turned off (`CheckoutReadiness::guard()`).

## Where they are set

**Admin > Payments and shipping** (`admin.shop-payments.edit`, administrators only, available with the catalog on),
shipping section:

- Store pickup: on/off and the address with directions.
- Zones (`app/Http/Controllers/Admin/ShippingZoneController.php`, routes `admin.shipping-zones.store`, `update`,
  `destroy`): name (up to 120 characters), fixed price typed as the customer reads it (`40.000` or `40000`, up to
  100.000.000), enabled and order.

MCP: `shipping_zones_list` and `shipping_zones_update` (whole list of zones plus pickup;
[../operations/mcp.md](../operations/mcp.md#orders-and-payments)).

## What an order keeps

`CheckoutService::place()` checks the chosen option again with the cart locked (a zone turned off meanwhile, or
pickup without an address, is refused) and copies onto the order:

- `delivery_method` (`pickup` or `delivery`);
- for delivery: `shipping_zone_id`, `shipping_zone_name` and `shipping_amount`, plus one `order_addresses` row of
  type `shipping` (address, city, department, reference);
- the total: lines + `shipping_amount` (0 for pickup).

Editing or deleting a zone never changes an existing order: it keeps the name and price it copied (the zone id is
set to null when the zone is deleted). The pickup address is **not** copied: the order page and the emails read the
current setting, so changing it also changes what older pickup orders show.

Bancard asks for the shipping costs to be visible before paying and for a shipping policy page with zones and
prices; the Bancard card lists both as informative requirements ([payments.md](payments.md#getting-a-client-on-bancard)).

## Tests

`CheckoutFlowTest` (pickup is free, delivery adds the zone price, address only with delivery, an unavailable zone is
refused) and `AdminShopPaymentsTest` (zones CRUD, pickup, the guard while selling).

# Soo Hungry

WhatsApp ordering and tiffin subscriptions for one merchant workspace. A café pickup becomes a single unpaid Clover order. A tiffin change updates the packing list, the schedule, and meal credits together. A business running both keeps one number and one staff inbox.

This is the pilot foundation from the 25 November 2026 specification. It runs on Laravel 12 and PHP 8.2. Live Meta and Clover calls stay behind `WHATSAPP_DRIVER=http` and `CLOVER_DRIVER=http`. The local app uses a signed webhook, a log sender, and a Clover sandbox stand-in so the kitchen flow can be exercised before production credentials are confirmed.

## Run

```bash
php artisan migrate:fresh --seed
php artisan serve
```

Staff login is at http://127.0.0.1:8000/login. The pilot password is `password`.

- `owner@soohungry.test` — Surrey Café, Lets Tiffin, and the hybrid workspace
- `service@soohungry.test` — Surrey Café counter
- `kitchen@soohungry.test` — Lets Tiffin packing

Under XAMPP, point the browser at http://localhost/soo_hungry/public and set `APP_URL` to that address. MySQL is optional; the local app uses SQLite.

The inbox has a pilot console. It runs the same pipeline as `POST /webhooks/whatsapp`. Set the message time in `America/Vancouver` when you want to try the 7:59 PM cutoff.

## Pilot rules already encoded

- A café-only “one lunch tomorrow” cannot create a tiffin subscription.
- Hybrid intent that is unclear asks: “Place a pickup order or manage your tiffin?”
- A meal request is timely only when the earliest clear message is at or before the local cutoff. The customer then has the grace window, 10 minutes in the pilot, to confirm it.
- A skip keeps the credit. Fulfillment consumes it once.
- November’s report stays as of 30 November after a December payment. Pickup tickets are not collections.
- One confirmation creates one Clover order. A timed-out create is reconciled before another create. Automatic kitchen routing and an explicit print are never both fired. A reprint is a marked copy.
- Opt-out blocks proactive reminders. Staff takeover stops automation. Retrying a failed send does not repeat a skip, a charge, or an order.

`php artisan test` covers those cases. `php artisan billing:reminders` queues due reminders and is scheduled every minute.

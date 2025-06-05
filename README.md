# DAH Booking Calendar

This WordPress plugin integrates the Dublin At Home booking calendar with Guesty.
It provides a shortcode that outputs a date picker, calculates pricing via the
Guesty API and redirects guests to a pre‑payment form.

## Installation
1. Copy the plugin folder into your site's `wp-content/plugins` directory.
2. Activate **DAH Booking Calendar** from the WordPress admin.
3. Ensure your theme loads jQuery and the [Flatpickr](https://flatpickr.js.org/) date picker.

## Usage
Add the `[dah_booking]` shortcode to any page. Configure property details in the
custom fields for each property post:

- `base_price` – nightly rate displayed before dates are selected
- `minnights` / `maxnights` – allowed booking range
- `deposit_percent` – deposit percentage if the Guesty API is unavailable
- `deposit_days` – how many days before arrival the deposit is due
- `security_deposit_fee` – optional security deposit amount

The calendar will query Guesty for availability and pricing. When a visitor
selects valid dates the booking form posts to your site and stores the order in
a cookie before redirecting to the `/prepayment` page.

## Development
JavaScript for the calendar lives in `js/dah-booking.js`. PHP endpoints for the
Guesty API are defined in `dah-calendar.php`. After modifying PHP files run:

```bash
php -l dah-calendar.php
```

This performs a basic syntax check.

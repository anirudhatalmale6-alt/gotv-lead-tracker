# GOTV Lead Tracker

A lightweight WordPress plugin that captures registration leads and tags each one with the exact campaign/source it came from.

## What it does
- **Registration form** — drop `[gotv_register]` on any page. Collects Name, Email, Phone.
- **Source tracking** — every campaign gets its own link, e.g. `yoursite.com/register/?src=facebook`. The `src` label is saved with the lead so you can sort registrations by where they came from. UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`) are captured automatically too.
- **Confirmation email** — sent automatically the moment a visitor registers. Editable subject + body under *Registrations → Email Settings* (`{name}` is replaced with the registrant's name).
- **Admin view** — *Registrations* menu in wp-admin: see every lead, filter by source, and export to CSV (respects the current filter).

## Install
1. Zip the `gotv-lead-tracker` folder (or use the provided zip).
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Activate.
3. Create a page (e.g. "Register") and add the shortcode `[gotv_register]`.
4. Build tracking links by adding `?src=your-label` to that page's URL.

## Shortcode options
`[gotv_register title="Register" button="Submit" thankyou="Thanks, you're in!"]`

## Tracking link examples
- `yoursite.com/register/?src=facebook`
- `yoursite.com/register/?src=flyer-jan`
- `yoursite.com/register/?src=radio-ad`

## Email delivery
Uses WordPress `wp_mail`. For rock-solid inbox delivery, pair with a transactional SMTP service (Brevo, Mailgun, SendGrid) via any SMTP plugin — the confirmation emails then route through that provider automatically.

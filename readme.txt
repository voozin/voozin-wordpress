=== Voozin – Transactional Email, Delivery Log and Inbound Email ===
Contributors: voozin
Tags: email, smtp, wp mail, transactional email, inbound email
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send all WordPress email through the Voozin API with a delivery log. Receive email to your domain inside wp-admin and reply from there.

== Description ==

WordPress sends password resets, order confirmations and form notifications with PHP's mail function by default. That mail often lands in spam or never arrives, and you can't see what happened. Voozin fixes both, and adds an inbox for email sent to your site's addresses.

**Send**

* Every `wp_mail()` call (WordPress core, WooCommerce, Contact Form 7, WPForms, Gravity Forms, membership plugins…) goes through the Voozin API over HTTPS. No SMTP ports, no SMTP plugin.
* Mail is signed with DKIM and aligned with SPF and DMARC for your domain, so Gmail, Outlook and Yahoo trust it.
* Pick the sender from your verified domains. Plugins that set their own From address on a verified domain keep it.
* Optional fallback to PHP mail if the API can't be reached.
* Send a test email from the settings page.

**Log**

* See every email WordPress sent: recipient, subject and status.
* With the webhook connected, statuses update to delivered, bounced or complained, so you know why a customer never got their password reset.
* The log is kept for 30 days by default.

**Receive and reply**

* One click creates the webhook and an inbound address such as `support@yoursite.com` in Voozin.
* Received email appears under Voozin → Inbox, grouped into conversations, with attachments.
* Reply from wp-admin; replies thread in the sender's mail app.
* Developers get a `voozin_inbound_received` action with the full parsed message to create tickets, leads or order notes.

Voozin also offers encrypted business mailboxes, marketing campaigns and a REST API with a Node.js SDK. See [voozin.com](https://voozin.com).

== Installation ==

1. Install and activate the plugin from Plugins → Add New, or upload the zip.
2. In Voozin, verify your domain and create an API key under Developers → API keys with the scopes `emails:send` and `domains:read`. For the inbox, also add `webhooks` and `inbound`.
3. In WordPress, open Voozin → Settings, paste the key (or define `VOOZIN_API_KEY` in `wp-config.php`), choose the From address and send a test email.
4. Optional: open Voozin → Inbox, click Connect and add an address to receive email.

Full guide: [voozin.com/docs/wordpress](https://voozin.com/docs/wordpress)

== Frequently Asked Questions ==

= Do I need a Voozin account? =

Yes. Voozin is the email service that delivers the mail. You can start a free 14-day trial without a card; transactional email is a paid add-on after the trial.

= Can I keep the API key out of the database? =

Yes. Add `define( 'VOOZIN_API_KEY', 'ic_live_...' );` to `wp-config.php`. The settings page then shows that the key comes from the constant.

= Does it work with WooCommerce and form plugins? =

Yes. The plugin replaces the delivery behind `wp_mail()`, which those plugins use.

= What happens if Voozin is unreachable or rejects an email? =

With "send with PHP mail instead" enabled (the default), WordPress sends the email the usual way and the log shows the Voozin error and notes the fallback. Without it, `wp_mail()` returns false and fires `wp_mail_failed`.

= Why doesn't the inbox receive anything? =

The domain's MX record must point to Voozin, the site must be reachable over HTTPS, and firewalls or security plugins must allow POST requests to `/wp-json/voozin/v1/webhook`.

= Is received mail end-to-end encrypted? =

No. Mail delivered to WordPress is decrypted by Voozin so it can be posted to your site. Use a Voozin mailbox for mail that must stay end-to-end encrypted.

== External services ==

This plugin connects to the Voozin API (https://api.voozin.com), operated by Neurochain Limited, to provide its features. It is required for the plugin to work.

* When WordPress sends an email, the recipients, subject, body, headers and attachments are sent to the API so Voozin can deliver it.
* When you open the settings page, the list of your verified domains is requested (cached for an hour).
* When you connect the inbox, the plugin creates a webhook endpoint and inbound routes in your Voozin account. Voozin then sends received emails and delivery updates to your site's REST API.
* Opening an attachment in the inbox downloads it from the API.

Nothing is sent before you add an API key. Voozin terms of service: https://voozin.com/terms. Privacy policy: https://voozin.com/privacy.

== Screenshots ==

1. Settings: API key, sender from your verified domains and a test email.
2. Email log with delivery statuses.
3. Inbox conversation with a reply.

== Changelog ==

= 0.1.0 =
* First release: wp_mail delivery through the Voozin API, delivery log, inbound inbox with threaded replies.

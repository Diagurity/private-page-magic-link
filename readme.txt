=== Client Page Link ===
Contributors: hanashiroworks
Tags: client portal, magic link, passwordless, private page, login link
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give each client a private page they open from a link in their email. No passwords, no login screen, and the link keeps working for weeks.

== Description ==

You make a page for a client: project status, files, an estimate. You want them to open it from your email, on their phone or their computer, today or three weeks from now, without creating a password.

Client Page Link does exactly that.

* Assign a client to any page (or any post type you choose). That page becomes private: everyone else gets your theme's normal "not found" page.
* Click "Send link by email". The client clicks the link and lands on their page, signed in.
* The link works any number of times until it expires (30 days by default, up to 365). Opening the same email on two devices just works.
* Client accounts cannot sign in with a password at all. There is nothing to guess and nothing to reset.
* Clients who lost the email can ask for a new one with the `[client_page_link_request]` form. The answer never reveals whether an address belongs to a client, and requests are rate limited.

= How it differs from other plugins =

* **Magic login plugins** send a link that signs a user in, usually for 5–15 minutes and only once. They are built to replace the login form. Client Page Link opens one specific page, and its links are built to sit in an inbox for weeks.
* **Client portal plugins** give each user a private page but still expect them to log in with a password.
* **Temporary login plugins** give a developer or support agent time-limited access to wp-admin. Clients here never see wp-admin.

= Security notes =

* Links are random 40-character tokens. Only a keyed hash is stored, so a copy of the database does not contain working links.
* Changing a page's client stops every link issued to the previous client.
* "Revoke all links" stops every link to the page and signs the client out everywhere.
* Private pages are sent with no-cache headers, marked noindex, and left out of archives, search, feeds, sitemaps, page lists and the REST API.
* A long lifetime means a forwarded email works for whoever receives it until it expires. Put on these pages what you would put in an email to the client, not passwords or payment details. Shorten the lifetime in Settings if your pages need more protection.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → Client Page Link and choose the post types (Pages by default) and the link lifetime.
3. Edit a page, pick or add a client in the "Client" box, and publish.
4. Click "Send link by email", or "Show a link to copy" to paste it into your own email.
5. Optionally, put `[client_page_link_request]` on a public page and mention it in your emails.

== Frequently Asked Questions ==

= What happens when a link expires? =

The client sees your normal 404 page. They can ask for a new link with the request form, or you can send one from the edit screen.

= I am an editor. Will clicking a client's link sign me out? =

No. Anyone who can already see the page keeps their own session.

= Does it work with page caching? =

Private pages send no-cache headers and define `DONOTCACHEPAGE`, which most caching plugins respect. Check that your server-level cache does not store pages for logged-in users.

= Can I change the email text? =

Use the `cplink_mail_subject` and `cplink_mail_body` filters. `cplink_lifetime` changes the lifetime in seconds, and `cplink_signed_in` runs when a client opens a link.

= What happens on uninstall? =

Settings, links and client assignments are removed. Client accounts are kept, without a role.

== Changelog ==

= 0.1.0 =
* First version.

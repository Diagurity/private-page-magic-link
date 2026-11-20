=== Client Page Link ===
Contributors: hanashiroworks
Tags: private page, magic link, passwordless, client portal, login link
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make a page that only one person can see, and send them a link that opens it. No passwords, no login screen, and the link keeps working for weeks.

== Description ==

You make a page for one person: a client's project status, a guest's itinerary, a member's documents. You want them to open it from your email, on their phone or their computer, today or three weeks from now, without creating a password.

Client Page Link does exactly that.

* Choose a **viewer** for any page (or any post type you enable). The page becomes private: only the viewer and people who can edit it, such as administrators, can see it. Everyone else gets your theme's normal "page not found".
* The edit screen always shows the **link for the viewer**, with a Copy button. Paste it into your own email, or click "Email this link".
* The link opens any number of times until it expires (30 days by default, up to 365). Opening the same email on two devices just works. Before it expires, a fresh link appears on the edit screen.
* Viewer accounts cannot sign in with a password at all. There is nothing to guess and nothing to reset.
* Viewers who lost the email can ask for a new one with the `[client_page_link_request]` form. The answer never reveals whether an address is registered, and requests are rate limited.

= How it differs from other plugins =

* **Magic login plugins** send a link that signs a user in, usually for 5–15 minutes and only once. They are built to replace the login form. Client Page Link opens one specific page, and its links are built to wait in an inbox for weeks.
* **Client portal plugins** give each user a private page but still expect them to log in with a password.
* **Temporary login plugins** give a developer or support agent time-limited access to wp-admin. Viewers here never see wp-admin.

= Security notes =

* Links carry a random 40-character token. The database keeps a keyed hash of every token, and the newest one encrypted with your site's secret keys (from wp-config.php) so the edit screen can show it. A copy of the database alone does not contain a working link.
* Choosing a different viewer stops every link issued to the previous one.
* "Replace the link" stops every link sent so far and signs the viewer out everywhere.
* Private pages are sent with no-cache headers, marked noindex, and left out of archives, search, feeds, sitemaps, page lists and the REST API.
* A long lifetime means a forwarded email works for whoever receives it until it expires. Put on these pages what you would put in an email to that person, not passwords or payment details. Shorten the lifetime in Settings if your pages need more protection.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → Client Page Link and choose the post types (Pages by default) and the link lifetime.
3. Edit a page. In the "Private page for a viewer" box below the content, choose a viewer or add a new one by email, and publish or update.
4. Copy the link shown in the box and send it, or click "Email this link".
5. Optionally, put `[client_page_link_request]` on a public page so viewers can ask for a new link.

== Frequently Asked Questions ==

= Who can see a private page? =

The viewer, and anyone who can edit the page: administrators, editors, and the page's author.

= What happens when a link expires? =

The viewer sees your normal "page not found". They can ask for a new link with the request form, or you can send the one shown on the edit screen.

= I am an administrator. Will clicking a viewer's link sign me out? =

No. Anyone who can already see the page keeps their own session.

= Does it work with page caching? =

Private pages send no-cache headers and define `DONOTCACHEPAGE`, which most caching plugins respect. Check that your server-level cache does not store pages for logged-in users.

= Can I change the email text? =

Use the `cplink_mail_subject` and `cplink_mail_body` filters. `cplink_lifetime` changes the lifetime in seconds, and `cplink_signed_in` runs when a viewer opens a link.

= What happens on uninstall? =

Settings, links and viewer assignments are removed. Viewer accounts are kept, without a role.

== Changelog ==

= 0.1.0 =
* First version.

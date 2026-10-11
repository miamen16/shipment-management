# Shipment Management for WordPress

Standalone shipment tracking and shipment administration. WooCommerce is not required.

## Features
- Public shipment tracking page with Arabic/English labels and RTL support.
- Shipment creation and assignment from the WordPress admin.
- Private agent dashboard; agents see only their assigned shipments.
- Agents can update status, location, and customer-visible timeline notes.
- Timeline history, email status notifications through WordPress `wp_mail()`.
- CSV import and export.

## Install
1. Upload this plugin folder as a ZIP via **Plugins → Add New Plugin → Upload Plugin**.
2. Activate **Shipment Management**.
3. Create a page containing `[shipment_tracking]`.
4. Create a page containing `[shipment_agent_dashboard]`.
5. Create agent accounts under **Users → Add New** and assign the **Shipment Agent** role.
6. Add shipments from the **Shipments** menu and assign each shipment to an agent.

## CSV format
Required columns: `tracking_code, customer_name, customer_email, customer_phone, destination, current_status, estimated_delivery`

Valid statuses: `received, processing, in_transit, at_facility, out_for_delivery, delivered, exception, returned`.

## Production notes
- Configure HTTPS and SMTP for reliable email delivery.
- WhatsApp automation is not included; it requires a WhatsApp Business Platform integration.
- Back up the database and test on staging before production use.
- Public tracking intentionally displays only tracking code, status, destination, estimated delivery, public note, and timeline. Review these fields for your privacy requirements.
- This is a starter plugin and should receive a security review and functional testing on a staging WordPress site before production deployment.

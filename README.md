# MUST Hotel Booking

MUST Hotel Booking is a WordPress plugin designed to help hotels manage room reservations directly from their website. It provides an integrated booking system that allows customers to check availability, make reservations, and manage bookings easily.

## Features

- Room availability management
- Online booking system
- Reservation management from WordPress dashboard
- Customer booking form
- Simple and lightweight integration
- Designed for hotel and accommodation websites

## Installation

1. Download or clone the repository.
2. Place the `must-hotel-booking` folder in your WordPress `/wp-content/plugins/` directory.
3. Go to **WordPress Admin -> Plugins**.
4. Activate **MUST Hotel Booking**.

## Usage

After activation:

1. Navigate to the plugin settings inside the WordPress dashboard.
2. Configure hotel rooms and booking options.
3. Add the booking functionality to any page using the provided shortcode or block.

## Release Readiness Checklist

Before publishing a GitHub release for WordPress updates:

1. Bump the plugin header `Version` in [must-hotel-booking.php](must-hotel-booking.php).
2. Bump `MUST_HOTEL_BOOKING_VERSION` in [must-hotel-booking.php](must-hotel-booking.php) to the exact same value.
3. Align `Stable tag` in [readme.txt](readme.txt) with that exact same version.
4. Create a matching Git tag in the format `vX.Y.Z`.
5. Publish exactly one canonical release ZIP asset named `must-hotel-booking-X.Y.Z.zip`.
6. If the repository or releases are private, define `MUST_HOTEL_BOOKING_GITHUB_TOKEN` in `wp-config.php`.
7. Test the update on staging from a previous installed version before production rollout.

## Requirements

- WordPress 5.0+
- PHP 7.4+

## License

This project is maintained by **MUST**.

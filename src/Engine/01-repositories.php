<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Database\AvailabilityRepository;
use MustHotelBooking\Database\CancellationPolicyRepository;
use MustHotelBooking\Database\GuestRepository;
use MustHotelBooking\Database\PaymentRepository;
use MustHotelBooking\Database\RatePlanRepository;
use MustHotelBooking\Database\ReservationRepository;
use MustHotelBooking\Database\RoomRepository;

function get_room_repository(): RoomRepository
{
    static $repository = null;

    if (!$repository instanceof RoomRepository) {
        $repository = new RoomRepository();
    }

    return $repository;
}

function get_guest_repository(): GuestRepository
{
    static $repository = null;

    if (!$repository instanceof GuestRepository) {
        $repository = new GuestRepository();
    }

    return $repository;
}

function get_reservation_repository(): ReservationRepository
{
    static $repository = null;

    if (!$repository instanceof ReservationRepository) {
        $repository = new ReservationRepository();
    }

    return $repository;
}

function get_availability_repository(): AvailabilityRepository
{
    static $repository = null;

    if (!$repository instanceof AvailabilityRepository) {
        $repository = new AvailabilityRepository();
    }

    return $repository;
}

function get_payment_repository(): PaymentRepository
{
    static $repository = null;

    if (!$repository instanceof PaymentRepository) {
        $repository = new PaymentRepository();
    }

    return $repository;
}

function get_rate_plan_repository(): RatePlanRepository
{
    static $repository = null;

    if (!$repository instanceof RatePlanRepository) {
        $repository = new RatePlanRepository();
    }

    return $repository;
}

function get_cancellation_policy_repository(): CancellationPolicyRepository
{
    static $repository = null;

    if (!$repository instanceof CancellationPolicyRepository) {
        $repository = new CancellationPolicyRepository();
    }

    return $repository;
}

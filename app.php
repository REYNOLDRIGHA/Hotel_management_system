<?php
declare(strict_types=1);

const APP_NAME = 'Hotel Management System';
const APP_BASE_URL = '/hms';
const APP_CURRENCY = 'KES';
const APP_TIMEZONE = 'Africa/Nairobi';

const SESSION_IDLE_TIMEOUT = 900; // 15 minutes
const LOYALTY_KES_PER_POINT = 100.0; // 1 point earned per KES 100 paid
const LOYALTY_REDEEM_KES_PER_POINT = 5.0; // KES 5 credit per point redeemed

date_default_timezone_set(APP_TIMEZONE);
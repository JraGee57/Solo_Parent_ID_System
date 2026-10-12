<?php
/**
 * Auth-policy constants, used by services/AuthService.php, so auth policy
 * lives in one obvious place.
 */

// Brute-force lockout: this many failed attempts locks the account for
// this many minutes, applicant and staff login alike.
const LOGIN_LOCKOUT_THRESHOLD = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

// Password reset code: how long a texted reset code stays valid.
const PASSWORD_RESET_CODE_MINUTES = 15;

// Mobile number verification: how long a texted code stays valid, and how many
// wrong entries void it (a new code must then be requested).
const MOBILE_CODE_MINUTES = 10;
const MOBILE_CODE_MAX_ATTEMPTS = 5;

// Passwords: at least this long, containing at least one letter and one number,
// and at most PASSWORD_MAX_BYTES (bcrypt ignores anything past 72 bytes, so a
// longer password would silently only be checked on its first 72).
const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_BYTES = 72;

// Sessions end after this long without any activity, and in any case this
// long after login -- so a session left open on a shared computer (e.g. a
// barangay hall or internet cafe) doesn't stay logged in.
const SESSION_IDLE_MINUTES = 30;
const SESSION_ABSOLUTE_HOURS = 12;

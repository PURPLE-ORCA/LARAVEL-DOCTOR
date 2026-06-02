<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Checks;

use PurpleOrca\Doctor\Contracts\DoctorCheck;
use PurpleOrca\Doctor\Contracts\DoctorCheckResult;

final class MailMailerCheck implements DoctorCheck
{
    public function name(): string
    {
        return 'MAIL_MAILER';
    }

    public function category(): string
    {
        return 'environment';
    }

    public function run(): DoctorCheckResult
    {
        $mailer = config('mail.default');

        if (blank($mailer)) {
            return DoctorCheckResult::fail(
                'MAIL_MAILER is not set',
                'Set MAIL_MAILER in your .env (e.g., MAIL_MAILER=smtp)'
            );
        }

        if ($mailer === 'log') {
            $env = config('app.env', 'production');

            if ($env === 'production') {
                return DoctorCheckResult::fail(
                    'MAIL_MAILER is set to "log" in production',
                    'Emails are being logged, not sent. Set MAIL_MAILER=smtp or your preferred driver.'
                );
            }

            return DoctorCheckResult::warn(
                "MAIL_MAILER is set to 'log' (env: {$env})",
                'Make sure to use a real mail driver in production'
            );
        }

        return DoctorCheckResult::pass("MAIL_MAILER is set to '{$mailer}'");
    }
}

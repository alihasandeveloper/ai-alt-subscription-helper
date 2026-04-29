<?php

namespace Aliha\AiAltSubscriptionHelper;

class AddFreeCredit
{
    public static function reset_free_users_credit()
    {
        $users = self::get_free_users();
        foreach ($users as $user) {
            if (self::is_monthly_anniversary($user)) {
                update_user_meta($user->ID, 'altg_available_token', 40);
            }
        }
    }

    private static function is_monthly_anniversary($user)
    {
        $registered = new \DateTime($user->user_registered);
        $today = new \DateTime('today');

        $one_month_later = clone $registered;
        $one_month_later->modify('+1 month');

        if ($today < $one_month_later) {
            return false;
        }

        $reg_day = (int) $registered->format('d');
        $today_day = (int) $today->format('d');
        $days_in_current_month = (int) $today->format('t');

        $effective_day = min($reg_day, $days_in_current_month);

        return $today_day === $effective_day;
    }

    private static function get_free_users()
    {
        $users = get_users([
            'meta_query' => [
                [
                    'key' => 'altg_total_token',
                    'value' => 40,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);
        return $users;
    }
}
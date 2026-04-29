<?php

namespace Aliha\AiAltSubscriptionHelper;

class AddFreeCredit
{
    private const FREE_CREDIT_AMOUNT = 40;

    public static function reset_free_users_credit(): void
    {
        $batch_size = 100;
        $offset = 0;
        $reset_users = [];

        while (true) {
            $users = self::get_free_users($offset, $batch_size);
            if (empty($users)) {
                break;
            }

            foreach ($users as $user) {
                if (self::is_monthly_anniversary($user->user_registered)) {
                    $current = (int) get_user_meta($user->ID, 'altg_available_token', true);

                    if ($current < self::FREE_CREDIT_AMOUNT) {
                        update_user_meta($user->ID, 'altg_available_token', self::FREE_CREDIT_AMOUNT);
                        $reset_users[] = $user->ID;
                    }
                }
            }
            $offset += $batch_size;
        }

        update_option('user_credit_reset', $reset_users);
    }

    private static function is_monthly_anniversary(string $user_registered): bool
    {
        $registered = new \DateTime($user_registered);
        $today = new \DateTime('today');
        $one_month_later = (clone $registered)->modify('+1 month');
        if ($today < $one_month_later) {
            return false;
        }

        $reg_day = (int) $registered->format('d');
        $days_in_month = (int) $today->format('t');

        $effective_day = min($reg_day, $days_in_month);

        return (int) $today->format('d') === $effective_day;
    }

    private static function get_free_users(int $offset = 0, int $limit = 100): array
    {
        return get_users([
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'altg_total_token',
                    'value' => self::FREE_CREDIT_AMOUNT,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => 'altg_available_token',
                    'value' => self::FREE_CREDIT_AMOUNT,
                    'compare' => '!=',
                    'type' => 'NUMERIC',
                ],
            ],
            'fields' => ['ID', 'user_registered'],
            'number' => $limit,
            'offset' => $offset,
        ]);
    }
}
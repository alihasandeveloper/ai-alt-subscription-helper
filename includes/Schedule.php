<?php

namespace Aliha\AiAltSubscriptionHelper;

class Schedule
{
    public function __construct()
    {
        add_action('atg_every_twelve_hours', [$this, 'atg_every_twelve_hours']);
    }

    public function atg_every_twelve_hours()
    {
        $this->altg_reset_monthly_subscription_credit();
    }

    private function log(string $message): void
    {
        error_log('AI Alt Subscription Helper (Schedule): ' . $message);
    }

    public function altg_reset_monthly_subscription_credit()
    {
        $batch_size = 50;
        $offset = 0;
        $today = date('Y-m-d');

        while (true) {
            $users = get_users([
                'meta_query' => [
                    [
                        'key' => 'altg_subscriptions',
                        'compare' => 'EXISTS',
                    ],
                ],
                'number' => $batch_size,
                'offset' => $offset,
            ]);

            if (empty($users)) break;

            foreach ($users as $user) {
                $user_id = $user->ID;

                $available_token = intval(get_user_meta($user_id, 'altg_available_token', true));
                $total_token = intval(get_user_meta($user_id, 'altg_total_token', true));
                $subscriptions = get_user_meta($user_id, 'altg_subscriptions', true);

                if (!is_array($subscriptions)) continue;

                $modified = false;

                foreach ($subscriptions as $key => $subscription) {

                    $plan_type   = $subscription['plan_type'] ?? '';
                    $reset_date  = $subscription['reset_date'] ?? '';
                    $limit       = intval($subscription['credit_limit'] ?? 0);
                    $expired_date = $subscription['expired_date'] ?? '';
                    $is_expired  = !empty($subscription['is_expired']);

                    // 1. Ignore Onetime plans from scheduling
                    if ($plan_type === 'onetime') continue;

                    // 2. Handle expiry FIRST
                    if (!empty($expired_date) && $today >= $expired_date) {
                        $subscriptions[$key]['is_expired'] = true;
                        $subscriptions[$key]['expired_date'] = $today;
                        $this->log("User {$user_id} {$plan_type} plan expired");
                        $modified = true;
                        continue;
                    }

                    // 3. Only process when reset date reached
                    if ($today < $reset_date) continue;

                    $modified = true;

                    // Logic: Monthly deduction for Monthly plans
                    if ($plan_type === 'monthly') {
                        $deduct = min($available_token, $limit);
                        $available_token = max(0, $available_token - $deduct);

                        // Also sync total_token (Monthly quota drain)
                        $total_token = max(0, $total_token - $deduct);

                        $subscriptions[$key]['reset_date'] = date('Y-m-d', strtotime($reset_date . ' +1 month'));
                        $this->log("Monthly drain for user {$user_id}");
                    }

                    // Logic: Reset for Yearly plans (Monthly credits reset)
                    elseif ($plan_type === 'yearly') {
                        // Deduct old month's balance (if any left from the quota)
                        $deduct = min($available_token, $limit);
                        $available_token = max(0, $available_token - $deduct);
                        $total_token = max(0, $total_token - $deduct);

                        // Add new month's credits
                        $available_token += $limit;
                        $total_token += $limit;

                        $subscriptions[$key]['reset_date'] = date('Y-m-d', strtotime($reset_date . ' +1 month'));
                        $this->log("Yearly monthly reset: user {$user_id}, +{$limit}");
                    }
                }

                if ($modified) {
                    update_user_meta($user_id, 'altg_available_token', $available_token);
                    update_user_meta($user_id, 'altg_total_token', $total_token);
                    update_user_meta($user_id, 'altg_subscriptions', $subscriptions);
                }
            }

            $offset += $batch_size;

            if ($offset > 100000) break;
        }
    }
}

<?php

namespace Aliha\AiAltSubscriptionHelper;

class Subscription
{
    public function __construct()
    {
        add_action('surecart/purchase_created', [$this, 'subscription_created'], 10, 2);
        add_action('surecart/subscription_renewed', [$this, 'subscription_renewed'], 10, 2);
        add_action('surecart/purchase_updated', [$this, 'subscription_updated'], 10, 2);
        add_action('surecart/purchase_revoked', [$this, 'subscription_cancelled'], 10, 2);
    }

    private function log(string $message): void
    {
        error_log('AI Alt Subscription Helper: ' . $message);

        if (defined('BDATG_DEBUG') && BDATG_DEBUG) {
            $log_file = WP_CONTENT_DIR . '/ai-alt-subscription.log';
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
        }
    }

    private function detect_plan_type(string $title): string
    {
        $title = strtolower($title);

        if (str_contains($title, 'yearly'))
            return 'yearly';
        if (str_contains($title, 'monthly'))
            return 'monthly';

        return 'onetime';
    }

    private function get_product_by_price_id($price_id)
    {
        static $cache = [];

        if (isset($cache[$price_id])) {
            return $cache[$price_id];
        }

        $posts = get_posts([
            'post_type' => 'product-credit',
            'numberposts' => 1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'price_id',
                    'value' => $price_id,
                ]
            ],
        ]);

        $cache[$price_id] = $posts[0] ?? null;
        return $cache[$price_id];
    }

    public function subscription_created($purchase): void
    {
        $user = $purchase->getWPUser();
        if (!$user)
            return;

        $user_id = $user->ID;
        $purchase_id = $purchase->id ?? null;
        $price_id = $purchase->price ?? null;

        if (!$purchase_id || !$price_id)
            return;

        $processed = get_user_meta($user_id, 'altg_processed_purchases', true) ?: [];
        if (in_array($purchase_id, $processed))
            return;

        $post = $this->get_product_by_price_id($price_id);
        if (!$post)
            return;

        $title = strtolower($post->post_title);
        $plan = $this->detect_plan_type($title);
        $credits = intval(get_post_meta($post->ID, 'credits', true));

        $available = intval(get_user_meta($user_id, 'altg_available_token', true));
        $total = intval(get_user_meta($user_id, 'altg_total_token', true));

        update_user_meta($user_id, 'altg_available_token', $available + $credits);
        update_user_meta($user_id, 'altg_total_token', $total + $credits);
        update_user_meta($user_id, 'altg_subscription_type', $plan);

        $processed[] = $purchase_id;
        update_user_meta($user_id, 'altg_processed_purchases', $processed);

        $this->push_subscription_history($user_id, $plan, $credits, $purchase_id, $price_id);
        $this->update_subscription($user_id, $title);
    }

    private function push_subscription_history(int $user_id, string $plan_type, int $credit_limit, string $sub_id, string $price_id): void
    {
        $purchase_date = date('Y-m-d');
        $expired_date = '';
        $reset_date = '';

        if ($plan_type === 'yearly') {
            $expired_date = date('Y-m-d', strtotime('+1 year'));
            $reset_date = date('Y-m-d', strtotime('+1 month'));
        } elseif ($plan_type === 'monthly') {
            $expired_date = date('Y-m-d', strtotime('+1 month'));
            $reset_date = date('Y-m-d', strtotime('+1 month'));
        }

        $new_entry = [
            'sub_id' => $sub_id,
            'price_id' => $price_id,
            'plan_type' => $plan_type,
            'credit_limit' => $credit_limit,
            'purchase_date' => $purchase_date,
            'reset_date' => $reset_date,
            'is_expired' => false,
            'expired_date' => $expired_date,
        ];

        $history = get_user_meta($user_id, 'altg_subscriptions', true);
        if (!is_array($history))
            $history = [];

        $history[] = $new_entry;
        update_user_meta($user_id, 'altg_subscriptions', $history);
    }

    public function subscription_renewed($subscription): void
    {
        $id = $subscription->id ?? null;
        if (!$id)
            return;

        $model = \SureCart\Models\Subscription::with(['customer', 'price'])->find($id);
        if (!$model || empty($model->customer->email))
            return;

        $user = get_user_by('email', $model->customer->email);
        if (!$user)
            return;

        $user_id = $user->ID;
        $price_id = $model->price->id ?? null;
        if (!$price_id)
            return;

        // Unique key for renewal: subscription_id + Month-Year
        $renewal_key = $id . '_' . date('Y_m');
        $processed = get_user_meta($user_id, 'altg_processed_renewal_events', true) ?: [];
        if (in_array($renewal_key, $processed))
            return;

        $post = $this->get_product_by_price_id($price_id);
        if (!$post)
            return;

        $credits = intval(get_post_meta($post->ID, 'credits', true));
        $title = strtolower($post->post_title);

        $available = intval(get_user_meta($user_id, 'altg_available_token', true));
        $total = intval(get_user_meta($user_id, 'altg_total_token', true));

        update_user_meta($user_id, 'altg_available_token', $available + $credits);
        update_user_meta($user_id, 'altg_total_token', $total + $credits);

        $processed[] = $renewal_key;
        update_user_meta($user_id, 'altg_processed_renewal_events', $processed);

        $this->update_subscription($user_id, $title);
    }

    public function subscription_updated($purchase): void
    {
        $user = $purchase->getWPUser();
        if (!$user)
            return;

        $user_id = $user->ID;
        $purchase_id = $purchase->id ?? null;
        $price_id = $purchase->price ?? null;

        if (!$purchase_id || !$price_id)
            return;

        $post = $this->get_product_by_price_id($price_id);
        if (!$post)
            return;

        $title = strtolower($post->post_title);
        $new_plan = $this->detect_plan_type($title);
        $new_limit = intval(get_post_meta($post->ID, 'credits', true));

        $available = intval(get_user_meta($user_id, 'altg_available_token', true));
        $total = intval(get_user_meta($user_id, 'altg_total_token', true));

        $history = get_user_meta($user_id, 'altg_subscriptions', true) ?: [];
        $found = false;

        foreach ($history as &$entry) {
            if ($entry['sub_id'] === $purchase_id) {
                // Deduct old credits
                $old_limit = intval($entry['credit_limit'] ?? 0);
                $available = max(0, $available - $old_limit);
                $total = max(0, $total - $old_limit);

                // Add new credits
                $available += $new_limit;
                $total += $new_limit;

                // Update entry
                $entry['plan_type'] = $new_plan;
                $entry['credit_limit'] = $new_limit;
                $entry['price_id'] = $price_id;

                // Update reset date for new cycle if needed
                if ($new_plan === 'yearly') {
                    $entry['reset_date'] = date('Y-m-d', strtotime('+1 month'));
                } elseif ($new_plan === 'monthly') {
                    $entry['reset_date'] = date('Y-m-d', strtotime('+1 month'));
                } else {
                    $entry['reset_date'] = '';
                }

                $found = true;
                break;
            }
        }

        if (!$found) {
            // Treat as new if not found in history (unlikely if already updated)
            $available += $new_limit;
            $total += $new_limit;
            $this->push_subscription_history($user_id, $new_plan, $new_limit, $purchase_id, $price_id);
        } else {
            update_user_meta($user_id, 'altg_subscriptions', $history);
        }

        update_user_meta($user_id, 'altg_available_token', $available);
        update_user_meta($user_id, 'altg_total_token', $total);
        update_user_meta($user_id, 'altg_subscription_type', $new_plan);
        $this->update_subscription($user_id, $title);
    }

    public function subscription_cancelled($purchase): void
    {
        $user = $purchase->getWPUser();
        if (!$user)
            return;

        $user_id = $user->ID;
        $purchase_id = $purchase->id ?? null;

        update_user_meta($user_id, 'altg_subscription_type', 'cancelled');
        update_user_meta($user_id, 'altg_subscription_name', '');

        $history = get_user_meta($user_id, 'altg_subscriptions', true);
        if (is_array($history)) {
            $available = intval(get_user_meta($user_id, 'altg_available_token', true));

            foreach ($history as &$entry) {
                // If this specific purchase is being cancelled
                if ($entry['sub_id'] == $purchase_id && empty($entry['is_expired'])) {
                    $deduct = intval($entry['credit_limit']);

                    // Decrease available balance only per user request
                    $available = max(0, $available - $deduct);

                    $entry['is_expired'] = true;
                    $entry['expired_date'] = date('Y-m-d');
                }
            }

            update_user_meta($user_id, 'altg_available_token', $available);
            update_user_meta($user_id, 'altg_subscriptions', $history);
        }
    }

    public function update_subscription(int $user_id, string $title): void
    {
        $title = strtolower($title);
        $plan = '';

        if (str_contains($title, 'one time'))
            $plan = 'One Time plan';
        elseif (str_contains($title, 'basic'))
            $plan = 'Basic plan';
        elseif (str_contains($title, 'standard'))
            $plan = 'Standard plan';
        elseif (str_contains($title, 'agency'))
            $plan = 'Agency plan';
        elseif (str_contains($title, 'enterprise'))
            $plan = 'Enterprise plan';

        if ($plan) {
            update_user_meta($user_id, 'altg_subscription_name', $plan);
        }
    }
}
<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\UsageDaily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Whether a client may send a message now, and counting what each message used. */
class Usage
{
    /**
     * Why the client's agent cannot answer now, or null when it can.
     *
     * @return array{status: int, code: string, message: string}|null
     */
    public function blocked(Tenant $tenant): ?array
    {
        if (! $tenant->isActive()) {
            return ['status' => 403, 'code' => 'account_suspended', 'message' => 'This account is suspended.'];
        }
        if (! $tenant->activeSubscription()) {
            return ['status' => 402, 'code' => 'subscription_inactive', 'message' => 'The subscription is not active. Please renew it.'];
        }
        if ($tenant->messagesUsed() >= $tenant->messagesAllowed()) {
            return ['status' => 429, 'code' => 'quota_exceeded', 'message' => 'The monthly message limit of this plan is reached.'];
        }

        return null;
    }

    public function cost(string $model, int $input, int $output): float
    {
        $price = config("chat.prices.{$model}");

        return $price ? ($input * $price['input'] + $output * $price['output']) / 1_000_000 : 0.0;
    }

    /** One customer message answered: on the conversation and on today's totals. */
    public function record(Tenant $tenant, Conversation $conversation, string $model, int $input, int $output): void
    {
        $cost = $this->cost($model, $input, $output);

        $conversation->forceFill([
            'user_messages' => $conversation->user_messages + 1,
            'input_tokens' => $conversation->input_tokens + $input,
            'output_tokens' => $conversation->output_tokens + $output,
            'cost' => (float) $conversation->cost + $cost,
        ])->save();

        $today = today()->toDateString();
        // One row per client and day (insertOrIgnore: two messages at the same moment don't both create it)
        if (! UsageDaily::where('tenant_id', $tenant->id)->whereDate('date', $today)->exists()) {
            UsageDaily::query()->insertOrIgnore(['tenant_id' => $tenant->id, 'date' => $today]);
        }
        UsageDaily::where('tenant_id', $tenant->id)->whereDate('date', $today)->update([
            'messages' => DB::raw('messages + 1'),
            'input_tokens' => DB::raw('input_tokens + '.$input),
            'output_tokens' => DB::raw('output_tokens + '.$output),
            'cost' => DB::raw('cost + '.number_format($cost, 6, '.', '')),
        ]);

        $this->warnIfNearLimit($tenant);
    }

    /** Emails the client's team once a period, when the messages used pass chat.warn_at of the plan. */
    private function warnIfNearLimit(Tenant $tenant): void
    {
        $start = $tenant->periodStart();
        $allowed = $tenant->messagesAllowed();
        if (! $start || $allowed <= 0 || $tenant->warned_for?->isSameDay($start)) {
            return;
        }
        $used = $tenant->messagesUsed();
        if ($used < $allowed * (float) config('chat.warn_at')) {
            return;
        }

        $tenant->forceFill(['warned_for' => $start->toDateString()])->save();
        $emails = $tenant->users()->pluck('email')->all();
        if ($emails === []) {
            return;
        }
        try {
            Mail::raw(
                "Hello {$tenant->name},\n\nYour AI chat has used {$used} of the {$allowed} messages of this month's plan "
                ."(the period ends on {$tenant->periodEnd()?->toDateString()}). When the limit is reached the chat stops answering until "
                ."the next period or an upgrade.\n\nYou can follow your usage in your dashboard: ".url('/portal'),
                fn ($m) => $m->to($emails)->subject('Your AI chat is near its monthly limit')
            );
        } catch (Throwable $e) {
            Log::warning('Quota email failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }
}

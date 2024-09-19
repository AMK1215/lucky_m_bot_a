<?php

namespace App\Http\Controllers\Api\V1\Webhook\Traits;

use App\Models\User;
use App\Models\Wager;
use App\Enums\WagerStatus;
use App\Models\Admin\Product;
use App\Models\SeamlessEvent;
use App\Enums\TransactionName;
use App\Models\Admin\GameType;
use App\Services\WalletService;
use App\Enums\TransactionStatus;
use App\Models\SeamlessTransaction;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\Slot\SlotWebhookRequest;
use App\Services\Slot\Dto\RequestTransaction;

trait RedisUseWebhook
{
    public function createEvent(SlotWebhookRequest $request): SeamlessEvent
    {
        // Cache event in Redis with TTL
        $ttl = 600; // Time-to-live (in seconds)
        Redis::setex('event:' . $request->getMessageID(), $ttl, json_encode($request->all()));

        Log::info('Event cached in Redis with TTL', [
            'key' => 'event:' . $request->getMessageID(),
            'value' => json_encode($request->all())
        ]);

        // Store event in the database
        return SeamlessEvent::create([
            'user_id' => $request->getMember()->id,
            'message_id' => $request->getMessageID(),
            'product_id' => $request->getProductID(),
            'request_time' => $request->getRequestTime(),
            'raw_data' => $request->all(),
        ]);
    }

    /**
     * @param array<int, RequestTransaction> $requestTransactions
     * @return array<int, SeamlessTransaction>
     *
     * @throws MassAssignmentException
     */
    public function createWagerTransactions($requestTransactions, SeamlessEvent $event, bool $refund = false)
    {
        $seamless_transactions = [];

        foreach ($requestTransactions as $requestTransaction) {
            $wager = Wager::firstOrCreate(
                ['seamless_wager_id' => $requestTransaction->WagerID],
                [
                    'user_id' => $event->user->id,
                    'seamless_wager_id' => $requestTransaction->WagerID,
                ]
            );

            if ($refund) {
                $wager->update(['status' => WagerStatus::Refund]);
            } elseif (!$wager->wasRecentlyCreated) {
                $wager->update([
                    'status' => $requestTransaction->TransactionAmount > 0 ? WagerStatus::Win : WagerStatus::Lose,
                ]);
            }

            $game_type = GameType::where('code', $requestTransaction->GameType)->firstOrFail();
            $product = Product::where('code', $requestTransaction->ProductID)->firstOrFail();

            $game_type_product = $game_type->products()->where('product_id', $product->id)->firstOrFail();
            $rate = $game_type_product->rate;

            $seamless_transactions[] = $event->transactions()->create([
                'user_id' => $event->user_id,
                'wager_id' => $wager->id,
                'game_type_id' => $game_type->id,
                'product_id' => $product->id,
                'seamless_transaction_id' => $requestTransaction->TransactionID,
                'rate' => $rate,
                'transaction_amount' => $requestTransaction->TransactionAmount,
                'bet_amount' => $requestTransaction->BetAmount,
                'valid_amount' => $requestTransaction->ValidBetAmount,
                'status' => $requestTransaction->Status,
            ]);
        }

        return $seamless_transactions;
    }

    public function processTransfer(User $from, User $to, TransactionName $transactionName, float $amount, int $rate, array $meta)
    {
        // Transfer the amount between wallets
        app(WalletService::class)->transfer(
            $from,
            $to,
            abs($amount),
            $transactionName,
            $meta
        );
    }
}
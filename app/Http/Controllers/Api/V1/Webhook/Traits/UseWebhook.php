<?php
namespace App\Http\Controllers\Api\V1\Webhook\Traits;

use Exception;
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
use Illuminate\Support\Facades\Auth;
use App\Models\Admin\GameTypeProduct;
use App\Services\Slot\Dto\RequestTransaction;
use App\Http\Requests\Slot\SlotWebhookRequest;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

trait UseWebhook
{
    public function createEvent(SlotWebhookRequest $request): SeamlessEvent
    {
        // // Cache event in Redis
        // Redis::set('event:' . $request->getMessageID(), json_encode($request->all()));
        // Set TTL in seconds (e.g., 600 seconds = 10 minutes)
        $ttl = 600; // Adjust TTL as per your requirements

        // Cache event in Redis with TTL
        Redis::setex('event:' . $request->getMessageID(), $ttl, json_encode($request->all()));

        // Log the caching event
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

            $game_type = GameType::where('code', $requestTransaction->GameType)->first();

            if (!$game_type) {
                throw new Exception("Game type not found for {$requestTransaction->GameType}");
            }

            $product = Product::where('code', $requestTransaction->ProductID)->first();

            if (!$product) {
                throw new Exception("Product not found for {$requestTransaction->ProductID}");
            }

            $game_type_product = GameTypeProduct::where('game_type_id', $game_type->id)
                ->where('product_id', $product->id)
                ->first();

            if (!$game_type_product) {
                throw new Exception("Game type product combination not found");
            }

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

// namespace App\Http\Controllers\Api\V1\Webhook\Traits;

// use Exception;
// use App\Models\User;
// use App\Models\Wager;
// use App\Enums\WagerStatus;
// use App\Models\Admin\Product;
// use App\Models\SeamlessEvent;
// use App\Enums\TransactionName;
// use App\Models\Admin\GameType;
// use App\Services\WalletService;
// use App\Enums\TransactionStatus;
// use App\Models\SeamlessTransaction;
// use Illuminate\Support\Facades\Auth;
// use App\Models\Admin\GameTypeProduct;
// use App\Services\Slot\Dto\RequestTransaction;
// use App\Http\Requests\Slot\SlotWebhookRequest;
// use Illuminate\Database\Eloquent\MassAssignmentException;
// use Illuminate\Contracts\Container\BindingResolutionException;

// trait UseWebhook
// {
//     public function createEvent(
//         SlotWebhookRequest $request,
//     ): SeamlessEvent {
//         return SeamlessEvent::create([
//             'user_id' => $request->getMember()->id,
//             'message_id' => $request->getMessageID(),
//             'product_id' => $request->getProductID(),
//             'request_time' => $request->getRequestTime(),
//             'raw_data' => $request->all(),
//         ]);
//     }

//     /**
//      * @param  array<int,RequestTransaction>  $requestTransactions
//      * @return array<int, SeamlessTransaction>
//      *
//      * @throws MassAssignmentException
//      */
//     public function createWagerTransactions(
//         $requestTransactions,
//         SeamlessEvent $event,
//         bool $refund = false
//     ) {
//         $seamless_transactions = [];

//         foreach ($requestTransactions as $requestTransaction) {
//             $wager = Wager::firstOrCreate(
//                 ['seamless_wager_id' => $requestTransaction->WagerID],
//                 [
//                     'user_id' => $event->user->id,
//                     'seamless_wager_id' => $requestTransaction->WagerID,
//                 ]
//             );

//             if ($refund) {
//                 $wager->update([
//                     'status' => WagerStatus::Refund,
//                 ]);
//             } elseif (! $wager->wasRecentlyCreated) {
//                 $wager->update([
//                     'status' => $requestTransaction->TransactionAmount > 0 ? WagerStatus::Win : WagerStatus::Lose,
//                 ]);
//             }

//             $game_type = GameType::where('code', $requestTransaction->GameType)->first();

//             if (! $game_type) {
//                 throw new Exception("Game type not found for {$requestTransaction->GameType}");
//             }
//             $product = Product::where('code', $requestTransaction->ProductID)->first();

//             if (! $product) {
//                 throw new Exception("Product not found for {$requestTransaction->ProductID}");
//             }

//             $game_type_product = GameTypeProduct::where('game_type_id', $game_type->id)
//                 ->where('product_id', $product->id)
//                 ->first();

//             $rate = $game_type_product->rate;
//             $user = Auth::user(); // Get the authenticated user


//             $seamless_transactions[] = $event->transactions()->create([
//                 'user_id' => $event->user_id,
//                 'wager_id' => $wager->id,
//                 'game_type_id' => $game_type->id,
//                 'product_id' => $product->id,
//                 'seamless_transaction_id' => $requestTransaction->TransactionID,
//                 'rate' => $rate,
//                 'transaction_amount' => $requestTransaction->TransactionAmount,
//                 'bet_amount' => $requestTransaction->BetAmount,
//                 'valid_amount' => $requestTransaction->ValidBetAmount,
//                 'status' => $requestTransaction->Status,
//                 //'agent_id' => $user->agent_id
//             ]);
//         }

//         return $seamless_transactions;
//     }

//     public function processTransfer(User $from, User $to, TransactionName $transactionName, float $amount, int $rate, array $meta)
//     {
//         // TODO: ask: what if operator doesn't want to pay bonus
//         app(WalletService::class)
//             ->transfer(
//                 $from,
//                 $to,
//                 abs($amount),
//                 $transactionName,
//                 $meta
//             );
//     }
// }
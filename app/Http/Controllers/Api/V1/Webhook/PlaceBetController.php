<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use Illuminate\Support\Facades\Redis;
use App\Enums\SlotWebhookResponseCode;
use App\Enums\TransactionName;
use App\Http\Controllers\Api\V1\Webhook\Traits\UseWebhook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Slot\SlotWebhookRequest;
use App\Models\SeamlessEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wager;
use App\Services\Slot\SlotWebhookService;
use App\Services\Slot\SlotWebhookValidator;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlaceBetController extends Controller
{
    use UseWebhook;

    public function placeBet(SlotWebhookRequest $request)
    {
        DB::beginTransaction();
        try {
            $validator = $request->check();

            if ($validator->fails()) {
                return $validator->getResponse();
            }

            $before_balance = $request->getMember()->balanceFloat;

             // Cache event in Redis before processing
            // Redis::set('event:' . $request->getMessageID(), json_encode($request->all()));
            // Redis::get('event:' . $request->getMessageID(), json_encode($request->all()));
            Redis::set('event:' . $request->getMessageID(), json_encode($request->all()));
            Log::info('Redis set event', ['messageID' => $request->getMessageID(), 'data' => $request->all()]);

            $cachedData = Redis::get('event:' . $request->getMessageID());
            Log::info('Redis get event', ['cachedData' => $cachedData]);
            $event = $this->createEvent($request);

            $seamless_transactions = $this->createWagerTransactions($validator->getRequestTransactions(), $event);

            foreach ($seamless_transactions as $seamless_transaction) {
                $this->processTransfer(
                    $request->getMember(),
                    User::adminUser(),
                    TransactionName::Stake,
                    $seamless_transaction->transaction_amount,
                    $seamless_transaction->rate,
                    [
                        'wager_id' => $seamless_transaction->wager_id,
                        'event_id' => $request->getMessageID(),
                        'seamless_transaction_id' => $seamless_transaction->id,
                    ]
                );
            }

            $request->getMember()->wallet->refreshBalance();

            $after_balance = $request->getMember()->balanceFloat;

            DB::commit();

            return SlotWebhookService::buildResponse(
                SlotWebhookResponseCode::Success,
                $after_balance,
                $before_balance
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
            ]);
        }
    }
}
<?php

namespace App\Jobs;

use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebateJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    protected $walletId;
    protected $depositAmount;

    /**
     * Create a new job instance.
     */
    public function __construct($walletId, $depositAmount)
    {
        $this->walletId = $walletId;
        $this->depositAmount = $depositAmount;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $rebateAmount = round($this->depositAmount * 0.01, 2);

        DB::transaction(function () use ($rebateAmount) {
            $wallet = Wallet::where('id', $this->walletId)->lockForUpdate()->first();
            $wallet->balance += $rebateAmount;
            $wallet->save();

            Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'rebate',
                'amount' => $rebateAmount,
            ]);
        });
    }

    public function getWalletId()
    {
        return $this->walletId;
    }

    public function getDepositAmount()
    {
        return $this->depositAmount;
    }
}

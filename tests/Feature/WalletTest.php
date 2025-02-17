<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Jobs\RebateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        Wallet::create(['user_id' => 1, 'balance' => 0,]);
    }

    public function test_deposit_with_rebate()
    {
        Queue::fake();

        $wallet = Wallet::create(['user_id' => 1, 'balance' => 0]);
        $depositAmount = 100;

        $response = $this->postJson('/api/wallet/deposit', [
            'wallet_id' => $wallet->id,
            'amount'    => $depositAmount,
        ]);

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Deposit & rebate success']);

        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type'      => 'deposit',
            'amount'    => $depositAmount,
        ]);

        Queue::assertPushed(RebateJob::class, function ($job) use ($wallet, $depositAmount) {
            return $job->getWalletId() == $wallet->id && $job->getDepositAmount() == $depositAmount;
        });
    }

    public function test_withdrawal_with_sufficient_balance()
    {
        $wallet = Wallet::first();
        $wallet->balance = 200;
        $wallet->save();

        $withdrawAmount = 50;

        $response = $this->postJson('/api/wallet/withdraw', [
            'wallet_id' => $wallet->id,
            'amount'    => $withdrawAmount,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Withdraw success']);

        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type'      => 'withdrawal',
            'amount'    => $withdrawAmount,
        ]);
    }

    public function test_withdrawal_with_insufficient_balance()
    {
        $wallet = Wallet::first();
        $wallet->balance = 30;
        $wallet->save();

        $withdrawAmount = 50;

        $response = $this->postJson('/api/wallet/withdraw', [
            'wallet_id' => $wallet->id,
            'amount'    => $withdrawAmount,
        ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Insufficient balance']);
    }

    public function test_concurrent_deposits_with_rebate()
    {
        Queue::fake();

        $wallet = Wallet::first();
        $depositAmount = 100;
        $times = 10;

        for ($i = 0; $i < $times; $i++) {
            $this->postJson('/api/wallet/deposit', [
                'wallet_id' => $wallet->id,
                'amount'    => $depositAmount,
            ]);
        }

        $wallet->refresh();

        $expectedBalance = $depositAmount * $times;
        $this->assertEquals($expectedBalance, $wallet->balance);

        $this->assertEquals($times, Transaction::where('wallet_id', $wallet->id)
            ->where('type', 'deposit')->count());

        Queue::assertPushed(RebateJob::class, $times);
    }
}

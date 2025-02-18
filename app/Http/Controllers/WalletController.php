<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Jobs\RebateJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function index()
    {
        $wallet = Wallet::firstOrCreate(['user_id' => 1], ['balance' => 0]);

        return view('wallet.index', compact('wallet'));
    }

    public function balance($walletId){
        $wallet = Wallet::findOrFail($walletId);
        return response()->json([
            'wallet_id' => $wallet->id,
            'balance' => $wallet->balance,
        ], 200);
    }

    public function transactions($walletId){
        $wallet = Wallet::findOrFail($walletId);
        $transactions = $wallet->transactions()->oderBy('created_at', 'desc')->get();
        return response()->json($trasactions, 200);
    }


//   Concurrency Handling:
//  - Uses database transactions (`DB::transaction`) to prevent race conditions
//  - lockForUpdate() to lock the wallet row until the transaction is completed, ensures only one process can modify the balance at a time

    public function deposit(Request $request){
        $request->validate([
            'wallet_id' => 'required|exists:wallets,id',
            'amount' => 'required|numeric|min:0.01'
        ]);

        $walletId = $request->input('wallet_id');
        $amount = $request->input('amount');

        DB::transaction(function () use ($walletId, $amount){
            $wallet = Wallet::where('id', $walletId)->lockForUpdate()->first();
            $wallet->balance += $amount;
            $wallet->save();

            Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'deposit',
                'amount' => $amount,
            ]);
        });

        RebateJob:: dispatch($walletId, $amount);

        return response()->json(['message'=> 'Deposit & rebate success', 200]);
    }

    public function withdraw(Request $request){
        $request->validate([
            'wallet_id' => 'required|exists:wallets,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $wallet = Wallet::where('id', $request->wallet_id)->lockForUpdate()->first();
        if (!$wallet || $wallet->balance < $request->amount) {
            return response()->json(['message' => 'Insufficient balance'], 400);
        }

        $result = DB::transaction(function () use ($wallet, $request){
            $wallet->balance -= $request->amount;
            $wallet->save();

            Transaction::create([
                'wallet_id'=>$wallet->id,
                'type'=>'withdrawal',
                'amount'=>$request->amount,
            ]);

            return true;
        });

        return response()->json(['message'=> 'Withdraw success', 200]);
    }
}
